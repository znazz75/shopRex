<?php

namespace ShopRex\Core;

/**
 * Lightweight ActiveRecord base - not a query builder. Concrete models
 * keep bespoke, prepared-SQL finder methods (ported verbatim from today's
 * inline queries) rather than a generic query DSL; this base only carries
 * the handful of things every model needs: the PDO accessor, snake_case-
 * to-camelCase property filling, and a generic save()/delete() for the
 * simple single-table cases (admin CRUD entities). Anything with child
 * collections (product options, shipping tiers, ...) or multi-step writes
 * overrides save() itself.
 */
abstract class Model
{
    protected static string $table = '';
    protected static string $primaryKey = 'id';

    public ?int $id = null;

    /** Fill public properties from a DB row (snake_case columns -> camelCase properties). */
    public function fill(array $row): static
    {
        foreach ($row as $column => $value) {
            $property = lcfirst(str_replace('_', '', ucwords($column, '_')));
            if (property_exists($this, $property)) {
                $this->$property = $value;
            }
        }
        return $this;
    }

    /** Reverse of fill(): camelCase properties -> snake_case columns, for INSERT/UPDATE. */
    public function toRow(): array
    {
        $row = [];
        foreach (get_object_vars($this) as $property => $value) {
            $column = strtolower((string)preg_replace('/(?<!^)[A-Z]/', '_$0', $property));
            $row[$column] = $value;
        }
        return $row;
    }

    protected static function pdo(): \PDO
    {
        return \Database::getConnection();
    }

    public static function find(int $id): ?static
    {
        $stmt = static::pdo()->prepare('SELECT * FROM ' . static::$table . ' WHERE ' . static::$primaryKey . ' = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? (new static())->fill($row) : null;
    }

    public static function findOrFail(int $id): static
    {
        $model = static::find($id);
        if ($model === null) {
            throw new \RuntimeException(static::class . " #{$id} not found.");
        }
        return $model;
    }

    public function delete(): bool
    {
        if ($this->id === null) {
            return false;
        }
        $stmt = static::pdo()->prepare('DELETE FROM ' . static::$table . ' WHERE ' . static::$primaryKey . ' = ?');
        return $stmt->execute([$this->id]);
    }
}
