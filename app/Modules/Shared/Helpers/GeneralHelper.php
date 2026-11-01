<?php

function formatEnumName($enumName)
{
    return str_replace('_', " ", $enumName);
}

function pushNewItem($newItem, $array)
{
    if(!in_array($newItem, $array))
    {
        $array[] = $newItem;
    }
    return $array;
}

function excludeKeysFromArray($oldArray, $excludedKeys)
{
    $newArray = array_filter($oldArray, function ($key) use ($excludedKeys) {
        return !in_array($key, $excludedKeys);
    }, ARRAY_FILTER_USE_KEY);
    $newArray = array_combine(array_keys($newArray), array_values($newArray));
    return $newArray;
}
/**
 * Locale for API response text. Arabic unless the request explicitly asks for a supported
 * language with Accept-Language (exactly "ar" or "en", as SetLocaleLang accepts), so clients
 * that never sent the header keep receiving the Arabic they always did.
 */
function api_locale(): string
{
    $header = strtolower(trim((string) request()?->header('Accept-Language')));

    return in_array($header, ['ar', 'en'], true) ? $header : 'ar';
}

/** Translate an API message in api_locale(). */
function api_trans(string $key, array $replace = []): string
{
    return __($key, $replace, api_locale());
}
