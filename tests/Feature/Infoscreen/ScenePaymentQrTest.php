<?php

use App\Modules\Events\Models\Event;
use App\Modules\Infoscreen\Domain\SceneConfig;
use App\Modules\Infoscreen\Enums\SceneType;
use App\Modules\Infoscreen\Models\InfoscreenScene;
use App\Modules\Infoscreen\Support\ScenePayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    fakeDiscord();
    fakeMumble();
});

it('fills data.qrSvg and data.caption for a payment-qr scene with a configured payload', function () {
    $event = Event::factory()->live()->create();

    $scene = InfoscreenScene::factory()->for($event)->create([
        'type' => SceneType::PaymentQr,
        'config' => new SceneConfig(
            qrPayload: 'https://pay.example.com/lan-2026',
            qrCaption: 'Bitte scannen für den Kostenbeitrag',
        ),
    ]);

    $payload = ScenePayload::for($scene);

    expect($payload['data'])->toHaveKey('qrSvg');
    expect($payload['data']['qrSvg'])->toContain('<svg');
    expect($payload['data']['caption'])->toBe('Bitte scannen für den Kostenbeitrag');
});

it('omits data.qrSvg for a payment-qr scene with an empty payload', function () {
    $event = Event::factory()->live()->create();

    $scene = InfoscreenScene::factory()->for($event)->create([
        'type' => SceneType::PaymentQr,
        'config' => new SceneConfig(qrPayload: '', qrCaption: 'Kostenbeitrag'),
    ]);

    $payload = ScenePayload::for($scene);

    expect($payload['data'])->not->toHaveKey('qrSvg');
    expect($payload['data']['caption'])->toBe('Kostenbeitrag');
});

it('omits data.qrSvg for a payment-qr scene with no payload configured at all', function () {
    $event = Event::factory()->live()->create();

    $scene = InfoscreenScene::factory()->for($event)->create([
        'type' => SceneType::PaymentQr,
        'config' => new SceneConfig,
    ]);

    $payload = ScenePayload::for($scene);

    expect($payload['data'])->not->toHaveKey('qrSvg');
});

it('fills data.logos with public storage URLs for a sponsors scene', function () {
    Storage::fake('public');

    $event = Event::factory()->live()->create();

    $file1 = UploadedFile::fake()->image('sponsor-a.png');
    $file2 = UploadedFile::fake()->image('sponsor-b.png');
    $path1 = $file1->store('sponsors', 'public');
    $path2 = $file2->store('sponsors', 'public');

    $scene = InfoscreenScene::factory()->for($event)->sponsors([$path1, $path2])->create();

    $payload = ScenePayload::for($scene);

    expect($payload['data']['logos'])->toBe([
        Storage::disk('public')->url($path1),
        Storage::disk('public')->url($path2),
    ]);
});

it('fills data.logos with an empty list for a sponsors scene with no logos configured', function () {
    $event = Event::factory()->live()->create();

    $scene = InfoscreenScene::factory()->for($event)->sponsors([])->create();

    $payload = ScenePayload::for($scene);

    expect($payload['data']['logos'])->toBe([]);
});
