<?php
declare(strict_types=1);

function clean_text(mixed $value): string
{
    return trim(is_string($value) ? $value : '');
}

function valid_id(mixed $value): ?int
{
    $result = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $result === false ? null : (int) $result;
}

function validate_ticket_input(array $input): array
{
    $data = [
        'title' => clean_text($input['title'] ?? ''),
        'description' => clean_text($input['description'] ?? ''),
        'category_id' => valid_id($input['category_id'] ?? null),
    ];
    $errors = [];
    if ($data['title'] === '' || mb_strlen($data['title']) > 150) {
        $errors[] = 'Title is required and must be 150 characters or fewer.';
    }
    if (mb_strlen($data['description']) < 10 || mb_strlen($data['description']) > 5000) {
        $errors[] = 'Description must be between 10 and 5,000 characters.';
    }
    if ($data['category_id'] === null) {
        $errors[] = 'Choose a valid category.';
    }
    return [$data, $errors];
}

