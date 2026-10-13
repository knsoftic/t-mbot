<?php

namespace Database\Factories;

use App\Enums\ContentSource;
use App\Enums\ContentStatus;
use App\Enums\ContentType;
use App\Models\Content;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Content>
 */
class ContentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => fake()->unique()->sentence(3),
            'type' => ContentType::Text,
            'source' => ContentSource::None,
            'text' => fake()->paragraph(),
            'status' => ContentStatus::Active,
        ];
    }

    public function image(string $url = 'https://cdn.example.org/image.jpg'): static
    {
        return $this->media(ContentType::Image, $url);
    }

    public function video(string $url = 'https://cdn.example.org/video.mp4'): static
    {
        return $this->media(ContentType::Video, $url);
    }

    public function document(string $url = 'https://cdn.example.org/file.pdf'): static
    {
        return $this->media(ContentType::Document, $url);
    }

    public function media(ContentType $type, string $url): static
    {
        return $this->state(fn () => [
            'type' => $type,
            'source' => ContentSource::Url,
            'text' => null,
            'caption' => fake()->sentence(),
            'media_url' => $url,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => ContentStatus::Inactive]);
    }
}
