<?php
/**
 * Shared filter builders (used by list pages and CSV export).
 */

/** @return array [string whereSql, array params, array cleanFilters] */
function mat_item_filters(array $in): array
{
    $f = [
        'q'      => trim((string)($in['q'] ?? '')),
        'cat'    => (int)($in['cat'] ?? 0),
        'status' => in_array($in['status'] ?? '', ['in', 'low', 'out', 'inactive'], true) ? $in['status'] : '',
    ];
    $w = [];
    $p = [];
    $w[] = $f['status'] === 'inactive' ? 'i.is_active = 0' : 'i.is_active = 1';
    if ($f['q'] !== '') {
        // every word must appear in code, description or bin location
        foreach (array_slice(preg_split('/\s+/', $f['q']), 0, 6) as $n => $word) {
            $w[] = "(i.code LIKE :q{$n} OR i.description LIKE :qd{$n} OR i.bin_location LIKE :qb{$n})";
            $p[":q{$n}"] = $p[":qd{$n}"] = $p[":qb{$n}"] = '%' . $word . '%';
        }
    }
    if ($f['cat'] > 0) {
        $w[] = 'i.category_id = :cat';
        $p[':cat'] = $f['cat'];
    }
    if ($f['status'] === 'in')  $w[] = 'i.quantity > 0';
    if ($f['status'] === 'out') $w[] = 'i.quantity <= 0';
    if ($f['status'] === 'low') $w[] = 'i.min_qty > 0 AND i.quantity <= i.min_qty';
    return ['WHERE ' . implode(' AND ', $w), $p, $f];
}

/** @return array [string whereSql, array params, array cleanFilters] */
function mat_txn_filters(array $in): array
{
    $isDate = fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : '';
    $f = [
        'q'      => trim((string)($in['q'] ?? '')),
        'type'   => in_array($in['type'] ?? '', ['in', 'out', 'adjust'], true) ? $in['type'] : '',
        'reason' => array_key_exists($in['reason'] ?? '', MAT_IN_REASONS + MAT_OUT_REASONS + ['count' => 1, 'opening' => 1]) ? $in['reason'] : '',
        'from'   => $isDate($in['from'] ?? ''),
        'to'     => $isDate($in['to'] ?? ''),
        'item'   => (int)($in['item'] ?? 0),
        'user'   => (int)($in['user'] ?? 0),
        'dept'   => trim((string)($in['dept'] ?? '')),
    ];
    $w = ['1=1'];
    $p = [];
    if ($f['q'] !== '') {
        $w[] = '(i.code LIKE :q1 OR i.description LIKE :q2 OR t.party LIKE :q3 OR t.reference LIKE :q4 OR t.department LIKE :q5)';
        foreach ([1, 2, 3, 4, 5] as $n) $p[":q$n"] = '%' . $f['q'] . '%';
    }
    if ($f['type'])   { $w[] = 't.txn_type = :type';  $p[':type'] = $f['type']; }
    if ($f['reason']) { $w[] = 't.reason = :reason';  $p[':reason'] = $f['reason']; }
    if ($f['from'])   { $w[] = 't.txn_date >= :from'; $p[':from'] = $f['from']; }
    if ($f['to'])     { $w[] = 't.txn_date <= :to';   $p[':to'] = $f['to']; }
    if ($f['item'])   { $w[] = 't.item_id = :item';   $p[':item'] = $f['item']; }
    if ($f['user'])   { $w[] = 't.user_id = :user';   $p[':user'] = $f['user']; }
    if ($f['dept'])   { $w[] = 't.department = :dept'; $p[':dept'] = $f['dept']; }
    return ['WHERE ' . implode(' AND ', $w), $p, $f];
}
