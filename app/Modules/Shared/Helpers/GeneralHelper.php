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