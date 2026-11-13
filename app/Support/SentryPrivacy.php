<?php

namespace App\Support;

use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\Tracing\SamplingContext;

/**
 * Keeps the tracker's requests (POST /api/collect, PATCH /api/collect/{visit},
 * POST /api/events) and the analytics data in them out of Sentry, and changes
 * nothing for any other request. Set in config/sentry.php as traces_sampler and
 * before_send (as [class, method] arrays: closures can't be cached by config:cache).
 *
 * - Tracing: these requests are never sampled, whatever traces_sample_rate is,
 *   so no transaction, span (with its cache keys) or profile is ever recorded
 *   for them. Every other request is sampled as before: the parent trace's
 *   decision when there is one, otherwise traces_sample_rate.
 * - Errors: an error in one of these requests is still reported (the exception
 *   and its stack trace), but without the request's body, headers, query string
 *   or cookies (only its URL and method), and without cache breadcrumbs, whose
 *   keys are derived from the visitor's IP (GeoIP lookup, rate limiter).
 */
final class SentryPrivacy
{
    /** The tracker's routes (routes/api.php), as paths with or without the leading slash. */
    private const TRACKER_PATH = '#^/?api/(collect(/[^/]+)?|events)/?$#';

    public static function isTrackerPath(?string $path): bool
    {
        return is_string($path) && preg_match(self::TRACKER_PATH, $path) === 1;
    }

    /** traces_sampler: 0 for the tracker; otherwise what Sentry would decide without a sampler. */
    public static function tracesSampler(SamplingContext $context): float
    {
        // The transaction's name is the request path (Sentry\Laravel\Tracing\Middleware).
        if (self::isTrackerPath($context->getTransactionContext()?->getName())) {
            return 0.0;
        }

        $parent = $context->getParentSampled();
        if ($parent !== null) {
            return $parent ? 1.0 : 0.0;
        }

        return (float) config('sentry.traces_sample_rate', 0.0);
    }

    /** before_send: an error in a tracker request keeps only the request's URL and method, and no cache breadcrumbs. */
    public static function beforeSend(Event $event, ?EventHint $hint = null): ?Event
    {
        $request = $event->getRequest();
        $path = isset($request['url']) && is_string($request['url']) ? parse_url($request['url'], PHP_URL_PATH) : null;

        if (! self::isTrackerPath($path) && ! self::isTrackerPath($event->getTransaction())) {
            return $event;
        }

        $event->setRequest(array_intersect_key($request, array_flip(['url', 'method'])));
        $event->setBreadcrumb(array_values(array_filter(
            $event->getBreadcrumbs(),
            fn (Breadcrumb $breadcrumb) => $breadcrumb->getCategory() !== 'cache',
        )));

        return $event;
    }
}
