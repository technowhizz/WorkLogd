<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Admin;

use Brick\Money\ISOCurrencyProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Shared plumbing for the admin portal's listing screens.
 *
 * Every index here is the same shape - search, sort, paginate - and the Vue side drives all three
 * through the query string, so the parsing belongs in one place rather than in nine controllers.
 */
abstract class Controller extends \App\Http\Controllers\Controller
{
    /**
     * The sort column and direction for a listing, constrained to columns the screen actually
     * offers so a hand-edited query string cannot order by anything it likes.
     *
     * @param  array<int, string>  $sortable
     * @return array{0: string, 1: string}
     */
    protected function sort(Request $request, array $sortable, string $default): array
    {
        $column = $request->string('sort')->toString();
        if (! in_array($column, $sortable, true)) {
            $column = $default;
        }

        $direction = $request->string('direction')->toString() === 'asc' ? 'asc' : 'desc';

        return [$column, $direction];
    }

    /**
     * A unique column to break ties on.
     *
     * Timestamps are stored at second precision, so a listing sorted only by created_at has no
     * defined order between rows made in the same second - and offset pagination over an unstable
     * order can show the same row on two pages and never show another.
     */
    protected function tiebreaker(): string
    {
        return 'id';
    }

    protected function perPage(Request $request): int
    {
        $perPage = $request->integer('per_page', 25);

        return in_array($perPage, [15, 25, 50, 100], true) ? $perPage : 25;
    }

    protected function search(Request $request): ?string
    {
        $search = trim($request->string('search')->toString());

        return $search === '' ? null : $search;
    }

    /**
     * The filter state echoed back to the page, so the controls keep showing what was asked for.
     *
     * @param  array<int, string>  $extra
     * @return array<string, string|null>
     */
    protected function filters(Request $request, array $extra = []): array
    {
        $filters = [
            'search' => $this->search($request),
            'sort' => $request->string('sort')->toString() ?: null,
            'direction' => $request->string('direction')->toString() ?: null,
            'per_page' => (string) $this->perPage($request),
        ];

        foreach ($extra as $key) {
            $value = $request->string($key)->toString();
            $filters[$key] = $value === '' ? null : $value;
        }

        return $filters;
    }

    /**
     * Every ISO currency, as value => label, for the currency selects.
     *
     * @return array<string, string>
     */
    protected function currencyOptions(): array
    {
        $options = [];
        foreach (ISOCurrencyProvider::getInstance()->getAvailableCurrencies() as $currency) {
            $options[$currency->getCurrencyCode()] = $currency->getName().' ('.$currency->getCurrencyCode().')';
        }

        return $options;
    }

    protected function back(string $message): RedirectResponse
    {
        return redirect()->back()->with([
            'bannerText' => $message,
            'bannerStyle' => 'success',
        ]);
    }

    protected function backWithError(string $message): RedirectResponse
    {
        return redirect()->back()->with([
            'bannerText' => $message,
            'bannerStyle' => 'danger',
        ]);
    }
}
