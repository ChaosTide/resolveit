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

/** User-facing message for a failed settings save: duplicate name, or a generic failure. */
function unique_error(PDOException $ex, string $thing): string
{
    $article = in_array(strtolower($thing[0]), ['a', 'e', 'i', 'o', 'u'], true) ? 'An' : 'A';
    return $ex->getCode() === '23000'
        ? "$article $thing with that name already exists."
        : "The $thing could not be saved.";
}
