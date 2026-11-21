<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Tools\Write\Concerns;

use App\Modules\Tournaments\Actions\CreateTournament;
use App\Modules\Tournaments\Enums\TournamentFormat;
use App\Support\DisplayTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Setup fields shared by tournament_create and tournament_update: the schema,
 * the Europe/Berlin -> UTC conversion and the validation rules of
 * {@see CreateTournament}.
 */
trait TournamentFields
{
    /** @return array<string, mixed> */
    protected function fieldSchema(JsonSchema $schema, bool $required): array
    {
        $string = fn (string $d) => $schema->string()->description($d);

        return [
            'name' => $string('Name des Turniers.')->required($required),
            'format' => $schema->string()->enum(array_map(fn (TournamentFormat $f) => $f->value, TournamentFormat::cases()))
                ->description('single_elimination, double_elimination oder round_robin.')->required($required),
            'team_size' => $schema->integer()->description('Spieler pro Team (1 = Einzelspieler).')->min(1)->max(32)->required($required),
            'starts_at' => $string('Start in Europe/Berlin, Format YYYY-MM-DD HH:MM.')->required($required),
            'game_id' => $schema->integer()->description('Spiel-ID aus games_list.'),
            'max_entries' => $schema->integer()->description('Maximale Anmeldungen (2 bis 1024).')->min(2)->max(1024),
            'checkin_opens_at' => $string('Check-in öffnet, Europe/Berlin, YYYY-MM-DD HH:MM.'),
            'checkin_closes_at' => $string('Check-in schließt, Europe/Berlin, YYYY-MM-DD HH:MM.'),
            'rules' => $string('Regeltext (höchstens 10000 Zeichen).'),
            'require_checkin' => $schema->boolean()->description('Check-in verlangen (Standard: ja).'),
        ];
    }

    /**
     * Model arguments -> action input (UTC times, settings), or a readable error.
     *
     * @param  array<string, mixed>  $arguments
     * @return array{0: array<string, mixed>, 1: string|null}
     */
    protected function toActionInput(array $arguments): array
    {
        $data = array_intersect_key($arguments, array_flip(CreateTournament::FIELDS));

        foreach (['starts_at', 'checkin_opens_at', 'checkin_closes_at'] as $field) {
            if (! isset($data[$field]) || $data[$field] === '') {
                unset($data[$field]);

                continue;
            }

            try {
                $data[$field] = CarbonImmutable::parse((string) $data[$field], DisplayTimezone::name())->utc()->format('Y-m-d H:i:s');
            } catch (Throwable) {
                return [[], "{$field}: ungültiges Datum, erwartet YYYY-MM-DD HH:MM (Europe/Berlin)."];
            }
        }

        if (array_key_exists('require_checkin', $arguments) && $arguments['require_checkin'] !== null) {
            $data['settings'] = ['require_checkin' => (bool) $arguments['require_checkin']];
        }

        return [$data, null];
    }

    /**
     * @param  array<string, mixed>  $data  action input
     */
    protected function validationError(array $data, bool $partial): ?string
    {
        $validator = Validator::make($data, CreateTournament::rules(partial: $partial));

        if (! $validator->fails()) {
            return null;
        }

        $errors = $validator->errors()->toArray();

        return implode(' ', array_map(fn (string $f, array $m): string => $f.': '.implode(' ', $m), array_keys($errors), array_values($errors)));
    }
}
