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
 * The primitive used here is add_option(), which compiles to a single INSERT
 * against a column with a unique index. Exactly one concurrent caller can
 * succeed; every other gets false from the database itself. wp_cache_add() is
 * deliberately NOT used as the claim, because it is only atomic when a
 * persistent object cache is installed and silently degrades to per-request
 * memory when one is not.
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
        $existing = get_option($option);

        if (false === $existing) {
            // Released between the INSERT failing and this read - try once more.
            return self::add($option, $expires);
        }

        if ((int) $existing > time()) {
            return false;
        }

        Logger::log(sprintf('Lock "%s" had expired (stale by %ds) - reclaiming.', $key, time() - (int) $existing));

        // Delete then re-INSERT rather than update_option(), so the reclaim is
        // itself a contended INSERT and only one caller can win it.
        delete_option($option);

        return self::add($option, $expires);
    }

    /**
     * Release a lock.
     *
     * @param string $key Logical lock name.
     * @return void
     */
    public static function release($key)
    {
        delete_option(self::option_name($key));
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
        $existing = get_option(self::option_name($key));

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
     * Insert the lock option, autoload off so locks never bloat alloptions.
     *
     * @param string $option Option name.
     * @param int    $expires Expiry timestamp.
     * @return bool
     */
    private static function add($option, $expires)
    {
        return (bool) add_option($option, $expires, '', 'no');
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
