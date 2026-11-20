<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class CvPublicController extends Controller
{
    public function home(Request $request): View|HttpResponse
    {
        if ($request->headers->has('X-Inertia')) {
            return Inertia::location(route('home'));
        }

        $settings = DB::table('site_settings')->whereIn('name', ['title', 'description'])->pluck('value', 'name');

        return view('landing', [
            'title' => $settings->get('title') ?: 'CV Generator — CV et lettre adaptés à une offre',
            'description' => $settings->get('description') ?: 'Importez votre CV et ajoutez le lien d’une offre. Préparez un CV lisible par les ATS et une lettre de motivation ciblée, en PDF et Word.',
            'canRegister' => (bool) config('cvgenerator.registration'),
            'canonical' => $this->publicUrl(),
            'indexable' => (bool) config('cvgenerator.indexable'),
        ]);
    }

    public function robots(): Response
    {
        if (! config('cvgenerator.indexable')) {
            return response("User-agent: *\nDisallow: /\n")->header('Content-Type', 'text/plain; charset=UTF-8');
        }

        $notes = DB::table('site_settings')->where('name', 'robots_notes')->value('value') ?: '';
        $rules = "User-agent: *\nAllow: /\nDisallow: /dashboard\nDisallow: /candidatures\nDisallow: /settings\nDisallow: /administration\nDisallow: /login\nDisallow: /register\n\n";

        return response($rules.$notes."\nSitemap: ".$this->publicUrl('sitemap.xml')."\n")
            ->header('Content-Type', 'text/plain; charset=UTF-8');
    }

    public function sitemap(): Response
    {
        $url = config('cvgenerator.indexable') ? '<url><loc>'.htmlspecialchars($this->publicUrl(), ENT_XML1 | ENT_QUOTES, 'UTF-8').'</loc></url>' : '';

        return response('<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.$url.'</urlset>')
            ->header('Content-Type', 'application/xml; charset=UTF-8');
    }

    public function llms(): Response
    {
        $text = DB::table('site_settings')->where('name', 'llms')->value('value')
            ?: "# CV Generator\n\n> Préparer un CV et une lettre adaptés à une offre d'emploi.\n\n- [Présentation](".$this->publicUrl().")\n";

        return response($text)->header('Content-Type', 'text/plain; charset=UTF-8');
    }

    private function publicUrl(string $path = ''): string
    {
        return rtrim((string) config('app.url'), '/').'/'.$path;
    }
}
