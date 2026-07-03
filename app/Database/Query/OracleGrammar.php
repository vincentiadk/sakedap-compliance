<?php

namespace App\Database\Query;

use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Database\Query\Builder;

class OracleGrammar extends Grammar
{
    public function compileSelect(Builder $query): string
    {
        $sql = parent::compileSelect($query);

        if ($query->unions && $query->aggregate) {
            return $sql;
        }

        if (!is_null($query->limit) || !is_null($query->offset)) {
            $sql = $this->compileAnsiLimit($query, $sql);
        }

        return $sql;
    }

    protected function compileAnsiLimit(Builder $query, string $sql): string
    {
        $offset = $query->offset ?? 0;

        if (!is_null($query->limit)) {
            $sql .= " fetch next {$query->limit} rows only";
        }

        if ($offset > 0) {
            $sql = preg_replace('/^select/i', "select * from (select inner_query.*, rownum rn from ($sql) inner_query where rownum <= " . ($offset + ($query->limit ?? 9999999)) . ") where rn > $offset --", $sql);
        }

        return $sql;
    }

    protected function compileLimit(Builder $query, $limit): string
    {
        return '';
    }

    protected function compileOffset(Builder $query, $offset): string
    {
        return '';
    }

    public function wrap($value, $prefixAlias = false): string
    {
        if ($this->isExpression($value)) {
            return $this->getValue($value);
        }

        if (str_contains(strtolower($value), ' as ')) {
            return $this->wrapAliasedValue($value, $prefixAlias);
        }

        return $this->wrapSegments(explode('.', $value));
    }

    protected function wrapValue($value): string
    {
        if ($value !== '*') {
            return strtoupper($value);
        }

        return $value;
    }
}
