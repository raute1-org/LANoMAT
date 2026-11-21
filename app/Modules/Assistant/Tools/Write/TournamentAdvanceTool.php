<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Tools\Write;

use App\Modules\Assistant\Support\ApprovalPreview;
use App\Modules\Assistant\Tools\ApprovableTool;
use App\Modules\Tournaments\Actions\AnnounceTournament;
use App\Modules\Tournaments\Actions\OpenCheckin;
use App\Modules\Tournaments\Actions\OpenTournamentEnrollment;
use App\Modules\Tournaments\Actions\ReopenToDraft;
use App\Modules\Tournaments\Actions\StartTournament;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Support\TournamentSetupCheck;
use App\Support\Modules\Module;
use Illuminate\Contracts\JsonSchema\JsonSchema;

final class TournamentAdvanceTool extends ApprovableTool
{
    /** @var array<string, array{label: string, action: class-string}> */
    private const STEPS = [
        'announce' => ['label' => 'assistant.card.step.announce', 'action' => AnnounceTournament::class],
        'open_enrollment' => ['label' => 'assistant.card.step.open_enrollment', 'action' => OpenTournamentEnrollment::class],
        'open_checkin' => ['label' => 'assistant.card.step.open_checkin', 'action' => OpenCheckin::class],
        'start' => ['label' => 'assistant.card.step.start', 'action' => StartTournament::class],
        'reopen_draft' => ['label' => 'assistant.card.step.reopen_draft', 'action' => ReopenToDraft::class],
    ];

    public static function toolName(): string
    {
        return 'tournament_advance';
    }

    /** @return list<Module> */
    public static function requiredModules(): array
    {
        return [Module::Tournaments];
    }

    public function description(): string
    {
        return 'Setzt ein Turnier einen Schritt im Ablauf weiter (nach Bestätigung auf einer Karte): '
            .'announce, open_enrollment, open_checkin, start oder reopen_draft. '
            .'Vor announce/open_enrollment müssen die Pflichtangaben stimmen (siehe tournament_get missing_setup_fields).';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'tournament_id' => $schema->integer()->description('ID aus tournaments_list.')->required(),
            'step' => $schema->string()->enum(array_keys(self::STEPS))->description('Der nächste Schritt.')->required(),
        ];
    }

    protected function precheck(array $arguments): ?string
    {
        $tournament = Tournament::query()->find((int) ($arguments['tournament_id'] ?? 0));

        if ($tournament === null) {
            return 'Not found: no tournament with this tournament_id (see tournaments_list).';
        }

        if (! isset(self::STEPS[(string) ($arguments['step'] ?? '')])) {
            return 'step: must be one of '.implode(', ', array_keys(self::STEPS)).'.';
        }

        if (! $this->can('manage', $tournament)) {
            return 'Forbidden: this account may not manage this tournament.';
        }

        if (in_array($arguments['step'], ['announce', 'open_enrollment'], true)) {
            $missing = TournamentSetupCheck::missingFields($tournament);

            if ($missing !== []) {
                return __('tournaments.errors.incomplete_setup', ['fields' => implode(', ', $missing)]);
            }
        }

        return null;
    }

    public function preview(array $arguments): ApprovalPreview
    {
        $tournament = Tournament::query()->findOrFail((int) $arguments['tournament_id']);
        $step = self::STEPS[(string) $arguments['step']];

        return new ApprovalPreview(__($step['label']), [
            ApprovalPreview::row(__('assistant.card.label.tournament'), $tournament->name),
            ApprovalPreview::row(__('assistant.card.label.status_now'), $tournament->status->value, true),
            ApprovalPreview::row(__('assistant.card.label.step'), __($step['label'])),
        ], danger: $arguments['step'] === 'start');
    }

    protected function execute(array $arguments, ?string $secret): array
    {
        $tournament = Tournament::query()->findOrFail((int) $arguments['tournament_id']);
        $this->authorize('manage', $tournament);

        $updated = app(self::STEPS[(string) $arguments['step']]['action'])->handle($tournament);

        return ['tournament_id' => $updated->id, 'status' => $updated->status->value];
    }
}
