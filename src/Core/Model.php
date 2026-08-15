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
 *
 * In plain terms: a "model" is a PHP object that represents one row of a
 * database table (e.g. one Product, one Order). This abstract base class
 * holds the small amount of code that's identical for every model - turning
 * a raw database row (array with snake_case keys like `product_id`) into an
 * object with normal PHP properties (camelCase like `$productId`), and back
 * again when saving. Concrete model classes (Models\Product, Models\Order,
 * ...) extend this and add their own table-specific finder methods.
 */
abstract class Model
{
    /** The database table this model reads from/writes to - set by each concrete subclass (e.g. 'products'). */
    protected static string $table = '';

    /** The name of this table's primary key column - 'id' for almost everything, but overridable per subclass if a table uses something else. */
    protected static string $primaryKey = 'id';

    /** The row's primary key value once loaded/saved, or null for a not-yet-saved (new) record. */
    public ?int $id = null;

    /** Fill public properties from a DB row (snake_case columns -> camelCase properties). */
    public function fill(array $row): static
    {
        foreach ($row as $column => $value) {
            // Converts e.g. "created_at" -> "created_at" split into words ->
            // "CreatedAt" (ucwords on the underscore-separated parts) ->
            // "createdAt" (lcfirst) - the standard snake_case-to-camelCase
            // conversion, done generically so subclasses don't need to
            // hand-write this mapping for every column.
            $property = lcfirst(str_replace('_', '', ucwords($column, '_')));
            // Only assign if the subclass actually declared a matching
            // property - this lets a row have extra/joined columns
            // (e.g. from a JOIN query) that simply get ignored instead of
            // causing an error.
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
            // Inserts an underscore before every capital letter that isn't
            // the very first character (the negative lookbehind (?<!^)),
            // then lowercases the whole thing - e.g. "createdAt" ->
            // "created_At" -> "created_at".
            $column = strtolower((string)preg_replace('/(?<!^)[A-Z]/', '_$0', $property));
            $row[$column] = $value;
        }
        return $row;
    }

    /** Shortcut to the shared PDO database connection (from the legacy Database class - see the db() shim in src/view-helpers.php for the non-OOP equivalent). */
    protected static function pdo(): \PDO
    {
        return \Database::getConnection();
    }

    /**
     * Looks up one row by its primary key and returns it hydrated as a model
     * instance, or null if no row has that id. This is the generic
     * equivalent of a per-model findById() - subclasses that need extra
     * WHERE conditions (e.g. "and status = 'active'") write their own finder
     * instead of using this one.
     */
    public static function find(int $id): ?static
    {
        // Prepared statement with the table/column names built from trusted
        // static properties (not user input) and only the numeric $id bound
        // as a parameter - safe from SQL injection.
        $stmt = static::pdo()->prepare('SELECT * FROM ' . static::$table . ' WHERE ' . static::$primaryKey . ' = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? (new static())->fill($row) : null;
    }

    /** Same as find(), but throws instead of returning null - use this where a missing row means something has gone wrong (e.g. an admin edit page for an id that must exist) rather than being a normal "not found" case to handle gracefully. */
    public static function findOrFail(int $id): static
    {
        $model = static::find($id);
        if ($model === null) {
            throw new \RuntimeException(static::class . " #{$id} not found.");
        }
        return $model;
    }

    /** Deletes this row from the database by its primary key. Returns false without querying if the model was never saved (no id) - there's nothing to delete. */
    public function delete(): bool
    {
        if ($this->id === null) {
            return false;
        }
        $stmt = static::pdo()->prepare('DELETE FROM ' . static::$table . ' WHERE ' . static::$primaryKey . ' = ?');
        return $stmt->execute([$this->id]);
    }
}
