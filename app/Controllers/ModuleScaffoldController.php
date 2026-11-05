<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\Http\Request;
use Ownswitch\Http\Response;
use Ownswitch\Support\Menu;

/** Navigable Magnus menu stubs — real routes, empty tables, no fake ops. */
final class ModuleScaffoldController extends BaseController
{
    public function show(Request $r, array $p = []): Response
    {
        $slug = (string) ($p['slug'] ?? '');
        $all = Menu::scaffolds();
        if (!isset($all[$slug])) {
            return $this->notFound();
        }
        $meta = $all[$slug];
        return $this->view('modules/scaffold', [
            'title' => $meta['title'],
            'blurb' => $meta['blurb'],
            'columns' => $meta['columns'],
            'slug' => $slug,
        ]);
    }
}
