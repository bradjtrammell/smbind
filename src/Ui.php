<?php
declare(strict_types=1);

namespace Smbind;

final class Ui
{
    public static function navLink(string $route, string $label, string $glyph, string $current): string
    {
        $active = $current === $route;
        $klass = $active
            ? 'nav-link bg-accent text-white'
            : 'nav-link text-ink/75 hover:bg-ink/5 hover:text-ink';
        $url = e(route($route));
        return '<a class="' . $klass . '" href="' . $url . '"' .
            ($active ? ' aria-current="page"' : '') . '><span aria-hidden="true" class="nav-glyph">' .
            e($glyph) . '</span><span>' . e($label) . '</span></a>';
    }

    public static function layout(string $title, string $body, ?array $user, ?array $flash = null): void
    {
        $page = e($title);
        $current = (string) ($_GET['route'] ?? 'dashboard');
        $nav = '';
        if ($user) {
            $nav .= self::navLink('dashboard', 'Overview', '◫', $current);
            $nav .= self::navLink('zones', 'DNS zones', '⌗', $current);
            if ($user['role'] === 'admin') {
                $nav .= self::navLink('users', 'Team access', '♙', $current);
                $nav .= self::navLink('activity', 'Activity log', '≡', $current);
            }
            $nav .= self::navLink('profile', 'My account', '◉', $current);
        }
        $alert = '';
        if ($flash) {
            $tone = $flash['kind'] === 'success' ? 'notice-success' : 'notice-error';
            $alert = '<div role="status" class="notice ' . $tone . '">' . e($flash['message']) . '</div>';
        }
        $identity = $user ? e($user['username']) : '';
        $role = $user ? e(ucfirst($user['role'])) : '';
        $logout = $user ? '<form method="post" action="/" class="mt-auto pt-6"><input type="hidden" name="_token" value="' .
            e(csrf()) . '"><input type="hidden" name="action" value="logout">' .
            '<button type="submit" class="nav-link w-full text-left text-ink/65 hover:bg-ink/5"><span class="nav-glyph">↪</span> Sign out</button></form>' : '';
        $mobile = $user ? '<details class="lg:hidden border-b border-line bg-white px-5 py-3"><summary class="cursor-pointer font-semibold text-ink">Navigation</summary><nav aria-label="Mobile navigation" class="mt-3 grid gap-1">' .
            $nav . $logout . '</nav></details>' : '';
        $sidebar = $user ? <<<HTML
<aside class="hidden lg:flex w-65 shrink-0 flex-col border-r border-line bg-white px-5 py-7">
 <a href="/?route=dashboard" class="flex items-center gap-3 mb-11 no-underline">
  <span class="brand-mark" aria-hidden="true">S<span>/</span></span>
  <span class="text-lg tracking-tight font-extrabold text-ink">SMBind <span class="text-xs font-semibold text-ink/45 align-middle">1.0</span></span>
 </a>
 <p class="px-3 mb-3 text-xs font-semibold text-ink/45">Workspace</p>
 <nav aria-label="Primary navigation" class="grid gap-1">$nav</nav>
 <div class="mt-auto border-t border-line pt-5 px-2 flex items-center gap-3">
   <span class="flex h-10 w-10 items-center justify-center rounded-full bg-moss/15 text-accent font-bold" aria-hidden="true">{$identity[0]}</span>
   <div class="min-w-0"><p class="font-semibold text-sm truncate">{$identity}</p><p class="text-xs text-ink/55">$role</p></div>
 </div>
 $logout
</aside>
HTML : '';
        $bodyWrapper = $user ? <<<HTML
<div class="min-h-screen lg:flex">
$sidebar
<div class="min-w-0 flex-1">
<header class="border-b border-line bg-white px-5 py-4 lg:px-11 lg:py-5 flex items-center justify-between gap-4">
  <span class="text-sm text-ink/55">DNS management <span aria-hidden="true">/</span> <strong class="font-semibold text-ink">$page</strong></span>
  <span class="text-xs font-semibold text-accent flex items-center gap-2"><span class="h-2 w-2 rounded-full bg-accent"></span> BIND control console</span>
</header>
$mobile
<main id="main" tabindex="-1" class="mx-auto w-full max-w-7xl px-5 py-8 lg:px-11 lg:py-11">
  $alert
  $body
</main>
</div></div>
HTML : '<main id="main" class="min-h-screen flex items-center justify-center px-5 py-12"><div class="w-full max-w-md">' . $alert . $body . '</div></main>';
        header('Content-Type: text/html; charset=UTF-8');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        header('X-Frame-Options: DENY');
        header('Content-Security-Policy: default-src \'self\'; script-src \'self\'; style-src \'self\'; img-src \'self\' data:; object-src \'none\'; base-uri \'none\'; form-action \'self\'; frame-ancestors \'none\'');
        echo '<!doctype html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">' .
            '<title>' . $page . ' · SMBind</title><link rel="stylesheet" href="/assets/app.css"></head>' .
            '<body class="bg-canvas font-sans text-ink antialiased"><a class="skip-link" href="#main">Skip to content</a>' .
            $bodyWrapper . '<script src="/assets/app.js" defer></script></body></html>';
    }

    public static function heading(string $title, string $description = '', string $action = ''): string
    {
        return '<div class="mb-9 flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">' .
            '<div><h1 class="text-3xl sm:text-4xl font-bold tracking-tight text-ink">' . e($title) . '</h1>' .
            ($description !== '' ? '<p class="mt-2 text-ink/65 leading-relaxed max-w-2xl">' . e($description) . '</p>' : '') .
            '</div>' . $action . '</div>';
    }

    public static function button(string $label, string $href, bool $secondary = false): string
    {
        return '<a class="' . ($secondary ? 'btn-secondary' : 'btn-primary') . '" href="' . e($href) . '">' . e($label) . '</a>';
    }

    public static function post(string $action, string $content, string $class = '', string $confirm = ''): string
    {
        $attr = $confirm === '' ? '' : ' data-confirm="' . e($confirm) . '"';
        return '<form method="post" action="/" class="' . e($class) . '"' . $attr . '>' .
            '<input type="hidden" name="_token" value="' . e(csrf()) . '">' .
            '<input type="hidden" name="action" value="' . e($action) . '">' . $content . '</form>';
    }

    public static function field(string $name, string $label, mixed $value = '', string $type = 'text', string $hint = '', bool $required = true): string
    {
        $id = 'field-' . preg_replace('/[^a-z0-9-]/', '-', $name);
        $min = $type === 'password' ? ' minlength="14" autocomplete="new-password"' : '';
        $autocomplete = $type === 'password' ? '' : ' autocomplete="off"';
        return '<label class="field" for="' . e($id) . '"><span class="field-label">' . e($label) . '</span>' .
            '<input class="field-input" id="' . e($id) . '" name="' . e($name) . '" type="' . e($type) . '" value="' .
            ($type === 'password' ? '' : e($value)) . '"' . ($required ? ' required' : '') . $min . $autocomplete . '>' .
            ($hint ? '<span class="field-hint">' . e($hint) . '</span>' : '') . '</label>';
    }

    public static function select(string $name, string $label, array $options, mixed $selected): string
    {
        $optionsHtml = '';
        foreach ($options as $value => $caption) {
            $optionsHtml .= '<option value="' . e($value) . '"' . ((string) $value === (string) $selected ? ' selected' : '') .
                '>' . e($caption) . '</option>';
        }
        return '<label class="field"><span class="field-label">' . e($label) .
            '</span><select class="field-input" name="' . e($name) . '">' . $optionsHtml . '</select></label>';
    }

    public static function status(bool $dirty): string
    {
        return $dirty ? '<span class="status-dirty"><span aria-hidden="true">●</span> Pending publish</span>' :
            '<span class="status-clean"><span aria-hidden="true">●</span> Published</span>';
    }

    public static function empty(string $title, string $description, string $action = ''): string
    {
        return '<div class="empty-state"><span class="empty-symbol" aria-hidden="true">⌗</span><h2 class="text-xl font-bold">' .
            e($title) . '</h2><p class="mt-2 text-ink/55">' . e($description) . '</p>' .
            ($action ? '<div class="mt-6">' . $action . '</div>' : '') . '</div>';
    }

    public static function panel(string $heading, string $content, string $hint = ''): string
    {
        return '<section class="surface"><div class="surface-title"><h2 class="text-lg font-bold">' . e($heading) . '</h2>' .
            ($hint ? '<p class="text-sm text-ink/55 mt-1">' . e($hint) . '</p>' : '') .
            '</div><div class="p-6 lg:p-8">' . $content . '</div></section>';
    }

    public static function table(array $heads, string $rows): string
    {
        $header = implode('', array_map(static fn (string $h): string => '<th scope="col">' . e($h) . '</th>', $heads));
        return '<div class="overflow-x-auto"><table class="data-table"><thead><tr>' . $header .
            '</tr></thead><tbody>' . $rows . '</tbody></table></div>';
    }
}
