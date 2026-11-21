<?php

namespace App\Modules\Catering\Actions;

use App\Models\User;
use App\Modules\Catering\Enums\FoodOrderStatus;
use App\Modules\Catering\Enums\FoodPaymentMethod;
use App\Modules\Catering\Models\FoodOrder;
use App\Modules\Catering\Models\Restaurant;
use App\Modules\Events\Models\Event;
use App\Modules\Registration\Support\PaymentLink;
use App\Support\SafeText;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Creates a food round of an event for a restaurant, always as a DRAFT: opening it stays a
 * separate human step (OpenFoodOrder). Title and payment default from the restaurant.
 */
class CreateFoodOrderDraft
{
    /**
     * @param  array{title?: string|null, opens_at?: string|null, closes_at?: string|null, payment_method?: string|null, payment_link?: string|null}  $fields
     */
    public function handle(User $actor, Event $event, Restaurant $restaurant, array $fields): FoodOrder
    {
        Gate::forUser($actor)->authorize('create', FoodOrder::class);

        $errors = [];

        $title = isset($fields['title']) && $fields['title'] !== '' ? SafeText::line($fields['title'], 256) : $restaurant->name;

        if ($title === null || mb_strlen($title) > 255) {
            $errors['title'] = 'must be 1 to 255 characters.';
        }

        $opens = $this->date($fields['opens_at'] ?? null, 'opens_at', $errors);
        $closes = $this->date($fields['closes_at'] ?? null, 'closes_at', $errors);

        if ($opens !== null && $closes !== null && $closes->lte($opens)) {
            $errors['closes_at'] = 'must be after opens_at.';
        }

        $method = $restaurant->default_payment_method ?? FoodPaymentMethod::Cash;
        $link = $restaurant->default_payment_method === FoodPaymentMethod::Paypal ? $restaurant->default_payment_link : null;

        if (($fields['payment_method'] ?? null) !== null && $fields['payment_method'] !== '') {
            $given = FoodPaymentMethod::tryFrom((string) $fields['payment_method']);

            if ($given === null) {
                $errors['payment_method'] = 'must be cash or paypal.';
            } else {
                $method = $given;
                $link = $given === FoodPaymentMethod::Paypal ? $link : null;
            }
        }

        if (($fields['payment_link'] ?? null) !== null && $fields['payment_link'] !== '') {
            if (! PaymentLink::isValid((string) $fields['payment_link'])) {
                $errors['payment_link'] = 'must be a valid https link (paypal.me needs a handle).';
            } elseif ($method === FoodPaymentMethod::Paypal) {
                $link = (string) $fields['payment_link'];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $order = new FoodOrder([
            'event_id' => $event->id,
            'restaurant_id' => $restaurant->id,
            'title' => $title,
            'opens_at' => $opens,
            'closes_at' => $closes,
            'payment_method' => $method,
            'payment_link' => $link,
        ]);
        $order->status = FoodOrderStatus::Draft;
        $order->save();

        Log::info('catering.round_create', [
            'user_id' => $actor->id,
            'event_id' => $event->id,
            'restaurant_id' => $restaurant->id,
            'food_order_id' => $order->id,
        ]);

        return $order;
    }

    /** @param array<string, string> $errors */
    private function date(?string $value, string $field, array &$errors): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            $errors[$field] = 'not a valid date-time (use ISO 8601, e.g. 2026-11-28T18:00:00+01:00).';

            return null;
        }
    }
}
