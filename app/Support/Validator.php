<?php

declare(strict_types=1);

namespace Ownswitch\Support;

/**
 * Field-spec driven validation used by generic CRUD controllers.
 * Spec keys: name,label,type(text|email|number|int|select|checkbox|textarea|password|prefix|ip|hidden|multiselect|datetime|trunk_waterfall),
 * required,max,min,options(array value=>label),pattern,default,help,list(bool),readonly_on_edit,list_only
 */
final class Validator
{
    /**
     * @param list<array<string,mixed>> $fields
     * @param array<string,mixed> $input
     * @return array{0:array<string,mixed>,1:array<string,string>} [clean data, errors]
     */
    public static function validate(array $fields, array $input, bool $isUpdate = false): array
    {
        $data = [];
        $errors = [];
        foreach ($fields as $f) {
            $name = (string) $f['name'];
            $type = (string) ($f['type'] ?? 'text');
            $label = (string) ($f['label'] ?? $name);
            if (!empty($f['list_only'])) {
                continue;
            }
            if (!empty($f['readonly_on_edit']) && $isUpdate) {
                continue;
            }
            if ($type === 'trunk_waterfall') {
                $type = 'multiselect';
            }
            if ($type === 'checkbox') {
                $data[$name] = !empty($input[$name]) ? 1 : 0;
                continue;
            }
            if ($type === 'multiselect') {
                $raw = $input[$name] ?? ($input[$name . '_csv'] ?? null);
                if (is_array($raw)) {
                    $parts = $raw;
                } else {
                    $parts = preg_split('/[\s,;|]+/', trim((string) ($raw ?? ''))) ?: [];
                }
                $allowed = array_map('strval', array_keys($f['options'] ?? []));
                $picked = [];
                foreach ($parts as $p) {
                    $p = trim((string) $p);
                    if ($p === '' || !in_array($p, $allowed, true) || in_array($p, $picked, true)) {
                        continue;
                    }
                    $picked[] = $p;
                }
                if ($picked === [] && !empty($f['required'])) {
                    $errors[$name] = "{$label} is required.";
                    continue;
                }
                $joined = implode(',', $picked);
                if (isset($f['max']) && mb_strlen($joined) > (int) $f['max']) {
                    $errors[$name] = "{$label} is too long.";
                    continue;
                }
                $data[$name] = $picked === [] ? ($f['default_empty'] ?? null) : $joined;
                continue;
            }
            $raw = $input[$name] ?? null;
            $val = is_string($raw) ? trim($raw) : $raw;
            if ($val === null || $val === '') {
                if (!empty($f['required']) && !($type === 'password' && $isUpdate)) {
                    $errors[$name] = "{$label} is required.";
                } elseif ($type !== 'password') {
                    $data[$name] = $f['default_empty'] ?? null; // empty optional => NULL
                }
                continue;
            }
            if (!is_string($val) && !is_int($val)) {
                $errors[$name] = "{$label} is invalid.";
                continue;
            }
            $val = (string) $val;
            if (isset($f['max']) && $type !== 'number' && $type !== 'int' && mb_strlen($val) > (int) $f['max']) {
                $errors[$name] = "{$label} must be at most {$f['max']} characters.";
                continue;
            }
            switch ($type) {
                case 'email':
                    if (!filter_var($val, FILTER_VALIDATE_EMAIL)) {
                        $errors[$name] = "{$label} must be a valid email.";
                        continue 2;
                    }
                    break;
                case 'number':
                    if (!preg_match('/^\d+(\.\d{1,8})?$/', $val)) {
                        $errors[$name] = "{$label} must be a non-negative decimal number (max 8 decimals).";
                        continue 2;
                    }
                    if (isset($f['min']) && bccomp($val, (string) $f['min'], 8) < 0) {
                        $errors[$name] = "{$label} must be at least {$f['min']}.";
                        continue 2;
                    }
                    if (isset($f['max']) && bccomp($val, (string) $f['max'], 8) > 0) {
                        $errors[$name] = "{$label} must be at most {$f['max']}.";
                        continue 2;
                    }
                    break;
                case 'int':
                    $allowNeg = !empty($f['allow_negative']) || (isset($f['min']) && (int) $f['min'] < 0);
                    if ($allowNeg) {
                        if (!preg_match('/^-?\d{1,10}$/', $val)) {
                            $errors[$name] = "{$label} must be a whole number.";
                            continue 2;
                        }
                    } elseif (!preg_match('/^\d{1,10}$/', $val)) {
                        $errors[$name] = "{$label} must be a whole number.";
                        continue 2;
                    }
                    if ((isset($f['min']) && (int) $val < (int) $f['min']) || (isset($f['max']) && (int) $val > (int) $f['max'])) {
                        $errors[$name] = "{$label} must be between " . ($f['min'] ?? 0) . ' and ' . ($f['max'] ?? 'max') . '.';
                        continue 2;
                    }
                    break;
                case 'select':
                    if (!in_array($val, array_map('strval', array_keys($f['options'] ?? [])), true)) {
                        $errors[$name] = "{$label} has an invalid selection.";
                        continue 2;
                    }
                    break;
                case 'prefix':
                    if (!preg_match('/^\d{1,20}$/', $val)) {
                        $errors[$name] = "{$label} must be digits only.";
                        continue 2;
                    }
                    break;
                case 'ip':
                    if (!filter_var($val, FILTER_VALIDATE_IP)) {
                        $errors[$name] = "{$label} must be a valid IPv4/IPv6 address.";
                        continue 2;
                    }
                    break;
                case 'datetime':
                    if (!preg_match('/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2})?)?$/', $val)) {
                        $errors[$name] = "{$label} must be YYYY-MM-DD or YYYY-MM-DD HH:MM:SS.";
                        continue 2;
                    }
                    $norm = str_replace('T', ' ', $val);
                    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $norm)) {
                        $norm .= ' 00:00:00';
                    } elseif (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $norm)) {
                        $norm .= ':00';
                    }
                    $dt = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $norm);
                    if ($dt === false || $dt->format('Y-m-d H:i:s') !== $norm) {
                        $errors[$name] = "{$label} is not a valid date/time.";
                        continue 2;
                    }
                    $val = $norm;
                    break;
                case 'password':
                    break;
            }
            if (!empty($f['pattern']) && !preg_match((string) $f['pattern'], $val)) {
                $errors[$name] = "{$label} has an invalid format.";
                continue;
            }
            $data[$name] = $val;
        }
        return [$data, $errors];
    }
}
