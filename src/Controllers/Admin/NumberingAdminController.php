<?php

namespace ShopRex\Controllers\Admin;

use ShopRex\Core\Container;
use ShopRex\Core\Request;
use ShopRex\Core\Response;

/**
 * Admin -> Numbering: lets an admin configure the format (prefix, suffix,
 * optional date component, zero-padding, starting value, increment, and
 * whether the counter resets when the date component changes) of every
 * admin-configurable sequential number this project issues - see
 * Services\NumberSequenceService for the allocation logic this configures.
 *
 * Unlike TaxRateAdminController, this is NOT an add/delete list - `type`
 * is a fixed, code-defined set (customer/invoice/rma_ticket/
 * withdrawal_request, all seeded in sql/schema.sql), so this screen only
 * ever edits those 4 existing rows, closer in shape to
 * SettingsAdminController's "loop known keys, validate, write" pattern
 * than to a generic CRUD list. Order numbers are deliberately NOT
 * configurable here - see sql/schema.sql's comment on the
 * number_sequences table for why.
 */
final class NumberingAdminController extends AdminCrudController
{
    // Every sequence type this screen edits, in display order - kept as a
    // whitelist so save() only ever writes rows that are actually meant to
    // exist, never an arbitrary POSTed type string.
    private const TYPES = ['customer', 'invoice', 'rma_ticket', 'withdrawal_request'];

    private readonly \PDO $pdo;

    public function __construct(Request $request, Container $container)
    {
        parent::__construct($request, $container);
        $this->pdo = $container->make(\PDO::class);
    }

    /** GET /admin/numbering - shows the current configuration of all 4 sequence types. */
    public function index(Request $request): Response
    {
        return $this->render('numbering/index', $this->viewData([]));
    }

    /** POST /admin/numbering - validates and saves every sequence type's row in one submit. */
    public function save(Request $request): Response
    {
        if ($csrfFailure = $this->requireCsrf()) {
            return $csrfFailure;
        }

        $errors = [];
        $prefixes = (array)$request->post('prefix', []);
        $suffixes = (array)$request->post('suffix', []);
        $dateFormats = (array)$request->post('date_format', []);
        $paddings = (array)$request->post('padding', []);
        $startValues = (array)$request->post('start_value', []);
        $increments = (array)$request->post('increment', []);
        $nextValues = (array)$request->post('next_value', []);
        $resetFlags = (array)$request->post('reset_on_date_change', []);

        // Validate every row before writing any of them - a mistake in one
        // sequence's fields shouldn't leave the others half-saved.
        $rows = [];
        foreach (self::TYPES as $type) {
            $prefix = trim((string)($prefixes[$type] ?? ''));
            $suffix = trim((string)($suffixes[$type] ?? ''));
            $dateFormat = trim((string)($dateFormats[$type] ?? ''));
            $padding = (int)($paddings[$type] ?? 6);
            $startValue = (int)($startValues[$type] ?? 1);
            $increment = (int)($increments[$type] ?? 1);
            $nextValue = (int)($nextValues[$type] ?? 1);
            $resetOnDateChange = !empty($resetFlags[$type]);

            if (strlen($prefix) > 20 || strlen($suffix) > 20) {
                $errors[] = __('admin.numbering.prefix_suffix_too_long', ['type' => __('admin.numbering.type_' . $type)]);
            }
            // Letters (the actual date() tokens), digits, and common
            // punctuation only - date() itself would happily accept
            // anything, but keeping this to a sane charset avoids a typo
            // producing a confusing/garbled number format.
            if (strlen($dateFormat) > 20 || !preg_match('/^[A-Za-z0-9\-_.,\/: ]*$/', $dateFormat)) {
                $errors[] = __('admin.numbering.date_format_invalid', ['type' => __('admin.numbering.type_' . $type)]);
            }
            if ($padding < 1 || $padding > 10) {
                $errors[] = __('admin.numbering.padding_range', ['type' => __('admin.numbering.type_' . $type)]);
            }
            if ($startValue < 0 || $nextValue < 0) {
                $errors[] = __('admin.numbering.value_negative', ['type' => __('admin.numbering.type_' . $type)]);
            }
            // Never 0 - a zero increment would leave the counter stuck on
            // the same number forever.
            if ($increment < 1) {
                $errors[] = __('admin.numbering.increment_min', ['type' => __('admin.numbering.type_' . $type)]);
            }

            $rows[$type] = [
                'prefix' => $prefix, 'suffix' => $suffix, 'date_format' => $dateFormat,
                'padding' => $padding, 'start_value' => $startValue, 'increment' => $increment,
                'next_value' => $nextValue, 'reset_on_date_change' => $resetOnDateChange ? 1 : 0,
            ];
        }

        if (!$errors) {
            $stmt = $this->pdo->prepare(
                'UPDATE number_sequences SET prefix=?, suffix=?, date_format=?, padding=?, start_value=?, increment=?, next_value=?, reset_on_date_change=? WHERE type=?'
            );
            foreach ($rows as $type => $row) {
                $stmt->execute([
                    $row['prefix'], $row['suffix'], $row['date_format'], $row['padding'],
                    $row['start_value'], $row['increment'], $row['next_value'], $row['reset_on_date_change'], $type,
                ]);
            }
            $this->flash('success', __('admin.numbering.flash_saved'));
            return $this->redirect('/admin/numbering');
        }

        // Re-render with the submitted (not-yet-saved) values so the admin
        // doesn't lose their edits on a validation error.
        return $this->render('numbering/index', $this->viewData($errors, $rows));
    }

    /**
     * Builds the view payload shared by index() and a failed save() -
     * $overrideRows lets a failed submit re-show what was just typed
     * instead of re-querying the (unsaved) database state.
     */
    private function viewData(array $errors, array $overrideRows = []): array
    {
        // FIELD(type, ?, ?, ...) orders the rows to match self::TYPES's
        // display order rather than whatever order they happen to be
        // stored in - needs its own bound params, so query() (no
        // placeholders) isn't an option here.
        $stmt = $this->pdo->prepare('SELECT * FROM number_sequences ORDER BY FIELD(type, ' . implode(',', array_fill(0, count(self::TYPES), '?')) . ')');
        $stmt->execute(self::TYPES);
        $sequences = [];
        foreach ($stmt->fetchAll() as $row) {
            $sequences[$row['type']] = $row;
        }
        // Overlay any just-submitted (not-yet-persisted) values from a
        // failed save so the form re-shows exactly what the admin typed.
        foreach ($overrideRows as $type => $row) {
            $sequences[$type] = array_merge($sequences[$type] ?? ['type' => $type], $row);
        }

        return [
            'errors' => $errors,
            'sequences' => $sequences,
            'types' => self::TYPES,
            'pageTitle' => __('admin.numbering'),
        ];
    }
}
