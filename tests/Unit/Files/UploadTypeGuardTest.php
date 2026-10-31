<?php

use App\Modules\Files\Support\UploadTypeGuard;

it('derives a normalised extension', function (string $name, string $extension) {
    expect(UploadTypeGuard::extension($name))->toBe($extension);
})->with([
    ['setup.EXE', 'exe'],
    ['a.tar.gz', 'gz'],
    ['page.html.', 'html'],
    ['page.html ', 'html'],
    ["page.html\0.zip", 'zip'],
    ['README', ''],
]);

it('blocks by extension, sniffed mime or client mime, and nothing else', function () {
    $guard = new UploadTypeGuard(['html', 'js'], ['text/html', 'image/svg+xml']);

    expect($guard->isBlockedType('a.html', 'text/plain', 'text/plain'))->toBeTrue()
        ->and($guard->isBlockedType('a.txt', 'text/html', 'text/plain'))->toBeTrue()
        ->and($guard->isBlockedType('a.txt', 'text/plain', 'image/svg+xml'))->toBeTrue()
        ->and($guard->isBlockedType('a.txt', 'text/plain', 'TEXT/HTML; charset=utf-8'))->toBeTrue()
        ->and($guard->isBlockedType('a.exe', 'application/x-dosexec', 'application/x-msdownload'))->toBeFalse()
        ->and($guard->isBlockedType('a.zip', null, null))->toBeFalse();
});

it('ships a default config that blocks html, svg and javascript only', function () {
    $guard = UploadTypeGuard::fromConfig();

    foreach (['a.html', 'a.htm', 'a.xhtml', 'a.svg', 'a.js', 'a.mjs'] as $name) {
        expect($guard->isBlockedType($name, null, null))->toBeTrue($name);
    }

    foreach (['a.exe', 'a.msi', 'a.zip', 'a.iso', 'a.png', 'a.pdf', 'a.bat', 'a.jar'] as $name) {
        expect($guard->isBlockedType($name, null, null))->toBeFalse($name);
    }
});
