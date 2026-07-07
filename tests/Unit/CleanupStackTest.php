<?php

use Noo\LaravelRemoteSync\Support\CleanupStack;

describe('CleanupStack', function () {
    it('runs cleanups in reverse order of registration', function () {
        $stack = new CleanupStack;
        $order = [];

        $stack->push('first', function () use (&$order) {
            $order[] = 'first';
        });
        $stack->push('second', function () use (&$order) {
            $order[] = 'second';
        });

        $result = $stack->run();

        expect($order)->toBe(['second', 'first'])
            ->and($result['ran'])->toBe(['second', 'first'])
            ->and($result['failed'])->toBe([]);
    });

    it('is idempotent', function () {
        $stack = new CleanupStack;
        $calls = 0;

        $stack->push('once', function () use (&$calls) {
            $calls++;
        });

        $stack->run();
        $stack->run();

        expect($calls)->toBe(1);
    });

    it('forgets cleanups by label', function () {
        $stack = new CleanupStack;
        $called = false;

        $stack->push('kept-snapshot', function () use (&$called) {
            $called = true;
        });

        expect($stack->has('kept-snapshot'))->toBeTrue();

        $stack->forget('kept-snapshot');
        $result = $stack->run();

        expect($called)->toBeFalse()
            ->and($stack->has('kept-snapshot'))->toBeFalse()
            ->and($result['ran'])->toBe([]);
    });

    it('keeps going when a cleanup throws and reports the failure', function () {
        $stack = new CleanupStack;
        $order = [];

        $stack->push('survives', function () use (&$order) {
            $order[] = 'survives';
        });
        $stack->push('explodes', function () {
            throw new RuntimeException('boom');
        });

        $result = $stack->run();

        expect($order)->toBe(['survives'])
            ->and($result['ran'])->toBe(['survives'])
            ->and($result['failed'])->toBe(['explodes' => 'boom']);
    });

    it('replaces a cleanup registered under the same label', function () {
        $stack = new CleanupStack;
        $calls = [];

        $stack->push('label', function () use (&$calls) {
            $calls[] = 'old';
        });
        $stack->push('label', function () use (&$calls) {
            $calls[] = 'new';
        });

        $stack->run();

        expect($calls)->toBe(['new']);
    });
});
