<?php
namespace Briqpay\WooCommerce;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Atomic Named Locks
 *
 * Replaces the check-then-set transient pattern:
 *
 *     if (get_transient($key)) { bail; }
 *     set_transient($key, 1, 30);
 *
 * That sequence is not atomic. Two concurrent requests can both pass the read
 * before either writes, so both proceed - which for this plugin means duplicate
 * orders, duplicate hosted payment pages, or a webhook processed twice.
 *
 * The primitive is a single INSERT IGNORE against wp_options, whose option_name
 * column has a unique index. Exactly one concurrent caller inserts the row;
 * every other gets zero affected rows from the database itself. Reads and
 * deletes go straight to the table too.
 *
 * add_option() is NOT atomic, though it looks it: it skips its existence check
 * when the option is in the request's notoptions cache, its INSERT is ON
 * DUPLICATE KEY UPDATE, and get_option() caches the value for the rest of the
 * request - so two callers could both win, and a caller waiting for a lock
 * never saw it released. It remains only as the fallback when $wpdb is not
 * available. wp_cache_add() is not used either: it is only atomic with a
 * persistent object cache and degrades silently to per-request memory without.
 *
 * Locks carry an expiry so a request that dies mid-flight cannot wedge a session
 * permanently.
 */
class Lock
{
    /**
     * Option name prefix.
     */
    const PREFIX = 'briqpay_lock_';

    /**
     * Try to claim a lock.
     *
     * @param string $key Logical lock name.
     * @param int    $ttl Seconds before the lock is considered abandoned.
     * @return bool True if this caller now holds the lock.
     */
    public static function acquire($key, $ttl = 30)
    {
        $option = self::option_name($key);
        $expires = time() + max(1, (int) $ttl);

        // Atomic: succeeds for exactly one caller.
        if (self::add($option, $expires)) {
            return true;
        }

        // Someone holds it. Take it over only if it is demonstrably abandoned.
        $existing = self::read($option);

        if (false === $existing) {
            // Released between the INSERT failing and this read - try once more.
            return self::add($option, $expires);
        }

        if ((int) $existing > time()) {
            return false;
        }

        Logger::log(sprintf('Lock "%s" had expired (stale by %ds) - reclaiming.', $key, time() - (int) $existing));

        // Delete then re-INSERT rather than update_option(), so the reclaim is
        // itself a contended INSERT and only one caller can win it. The delete
        // only matches the expired value, so a lock another caller has just
        // reclaimed is never removed.
        self::remove($option, $existing);

        return self::add($option, $expires);
    }

    /**
     * Try to claim a lock, waiting up to $wait seconds for the holder to let go.
     *
     * For paths that must not simply give up when another request is busy with
     * the same resource - the customer's return and a webhook for the same order
     * routinely land within the same second.
     *
     * @param string $key  Logical lock name.
     * @param int    $ttl  Seconds before the lock is considered abandoned.
     * @param int    $wait Seconds to keep retrying before giving up.
     * @return bool True if this caller now holds the lock.
     */
    public static function acquire_wait($key, $ttl = 30, $wait = 10)
    {
        $deadline = microtime(true) + max(0, (int) $wait);

        while (!self::acquire($key, $ttl)) {
            if (microtime(true) >= $deadline) {
                return false;
            }
            usleep(250000);
        }

        return true;
    }

    /**
     * The lock every path that changes a Briqpay order's status shares.
     *
     * The return handler, the webhooks and the janitor each read the order,
     * decide from its status, and write a new one. Run concurrently, both read
     * the same old status and both write - which is how an order got two
     * pending -> on-hold transitions, and with them two stock reductions and two
     * customer emails.
     *
     * @param int $order_id Order id.
     * @return string
     */
    public static function order_key($order_id)
    {
        return 'briqpay_order_' . (int) $order_id;
    }

    /**
     * Release a lock.
     *
     * @param string $key Logical lock name.
     * @return void
     */
    public static function release($key)
    {
        self::remove(self::option_name($key));
    }

    /**
     * Is the lock currently held by someone?
     *
     * Informational only - never branch on this to decide whether to proceed,
     * because between the read and the action another request can claim it. Use
     * acquire().
     *
     * @param string $key Logical lock name.
     * @return bool
     */
    public static function is_held($key)
    {
        $existing = self::read(self::option_name($key));

        return false !== $existing && (int) $existing > time();
    }

    /**
     * Claim a one-time marker that is never released.
     *
     * For idempotency rather than mutual exclusion: the first caller for a given
     * key wins and every later one is told it is a duplicate, until the marker
     * expires. Used for webhook deduplication, where the point is not to
     * serialize work but to ensure one delivery of an event is processed once.
     *
     * @param string $key Logical marker name.
     * @param int    $ttl Seconds the marker remains claimed.
     * @return bool True if this caller is the first.
     */
    public static function claim_once($key, $ttl = 300)
    {
        return self::acquire($key, $ttl);
    }

    /**
     * Insert the lock row, autoload off so locks never bloat alloptions.
     *
     * @param string $option Option name.
     * @param int    $expires Expiry timestamp.
     * @return bool True only for the caller whose INSERT created the row.
     */
    private static function add($option, $expires)
    {
        $db = self::db();
        if ($db) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $inserted = $db->query($db->prepare(
                "INSERT IGNORE INTO {$db->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
                $option,
                (string) $expires
            ));

            return 1 === (int) $inserted;
        }

        return (bool) add_option($option, $expires, '', 'no');
    }

    /**
     * Read the lock row, never from a cache.
     *
     * @param string $option Option name.
     * @return string|false Expiry timestamp, or false when there is no lock.
     */
    private static function read($option)
    {
        $db = self::db();
        if ($db) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $value = $db->get_var($db->prepare(
                "SELECT option_value FROM {$db->options} WHERE option_name = %s",
                $option
            ));

            return null === $value ? false : $value;
        }

        return get_option($option);
    }

    /**
     * Delete the lock row.
     *
     * @param string      $option   Option name.
     * @param string|null $expected Only delete while it still holds this value.
     * @return void
     */
    private static function remove($option, $expected = null)
    {
        $db = self::db();
        if ($db) {
            $where = array('option_name' => $option);
            if (null !== $expected) {
                $where['option_value'] = (string) $expected;
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $db->delete($db->options, $where);
            return;
        }

        delete_option($option);
    }

    /**
     * The WordPress database, when one is available.
     *
     * @return \wpdb|null
     */
    private static function db()
    {
        global $wpdb;

        if (is_object($wpdb) && !empty($wpdb->options)
            && method_exists($wpdb, 'query') && method_exists($wpdb, 'prepare')
            && method_exists($wpdb, 'get_var') && method_exists($wpdb, 'delete')) {
            return $wpdb;
        }

        return null;
    }

    /**
     * Hash the key so arbitrary identifiers cannot exceed the option name column.
     *
     * @param string $key Logical lock name.
     * @return string
     */
    private static function option_name($key)
    {
        return self::PREFIX . md5((string) $key);
    }
}
