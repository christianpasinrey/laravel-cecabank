<?php

namespace Cpr\Cecabank\Database\Factories;

use Cpr\Cecabank\Models\PaymentGateway;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<PaymentGateway>
 */
class PaymentGatewayFactory extends Factory
{
    protected $model = PaymentGateway::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->company(),
            'provider' => 'cecabank',
            'merchant_id' => $this->faker->numerify('#########'),
            'acquirer_bin' => $this->faker->numerify('##########'),
            'terminal_id' => $this->faker->numerify('########'),
            'encryption_key_test' => $this->faker->sha256(),
            'encryption_key_prod' => $this->faker->sha256(),
            'environment' => 'test',
            'currency' => '978',
            'language' => '1',
            'is_active' => false,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => true]);
    }

    public function production(): static
    {
        return $this->state(fn (array $attributes) => ['environment' => 'production']);
    }
}
