<?php

use App\Enums\PaymentStatusEnum;

it('lets a pending payment reach every outcome', function (PaymentStatusEnum $target) {
    expect(PaymentStatusEnum::Pending->canTransitionTo($target))->toBeTrue();
})->with([
    PaymentStatusEnum::Paid,
    PaymentStatusEnum::Expired,
    PaymentStatusEnum::Failed,
    PaymentStatusEnum::Cancelled,
]);

it('closes a payment for good once it leaves pending', function (PaymentStatusEnum $from) {
    expect($from->isTerminal())->toBeTrue();

    foreach (PaymentStatusEnum::cases() as $target) {
        expect($from->canTransitionTo($target))->toBeFalse();
    }
})->with([
    PaymentStatusEnum::Paid,
    PaymentStatusEnum::Expired,
    PaymentStatusEnum::Failed,
    PaymentStatusEnum::Cancelled,
]);

it('refuses to settle a payment that already ended another way', function (PaymentStatusEnum $from) {
    expect($from->canTransitionTo(PaymentStatusEnum::Paid))->toBeFalse();
})->with([
    PaymentStatusEnum::Expired,
    PaymentStatusEnum::Failed,
    PaymentStatusEnum::Cancelled,
]);

it('treats a repeated status as a no-op rather than a transition', function () {
    expect(PaymentStatusEnum::Pending->canTransitionTo(PaymentStatusEnum::Pending))->toBeFalse()
        ->and(PaymentStatusEnum::Paid->canTransitionTo(PaymentStatusEnum::Paid))->toBeFalse();
});
