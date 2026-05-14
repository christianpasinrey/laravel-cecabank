<?php

namespace Cpr\Cecabank\Database\Factories;

use Cpr\Cecabank\Models\PaymentGateway;
use Cpr\Cecabank\Models\PaymentTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<PaymentTransaction>
 */
class PaymentTransactionFactory extends Factory
{
    protected $model = PaymentTransaction::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'payable_type' => null,
            'payable_id' => null,
            'payment_gateway_id' => PaymentGateway::factory(),
            'operation_number' => 'OP'.$this->faker->unique()->numerify('####').'T'.$this->faker->unixTime(),
            'amount' => $this->faker->randomFloat(2, 5, 50),
            'status' => 'pending',
            'environment' => 'production',
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
            'authorization_number' => $this->faker->numerify('######'),
            'reference' => $this->faker->numerify('############'),
            'completed_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
            'error_message' => 'Payment declined',
        ]);
    }
}
