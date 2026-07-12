<?php

declare(strict_types=1);

namespace SupportAI\Http\Controller;

use SupportAI\Application\Eval\EvalRunner;
use SupportAI\Http\Request;
use SupportAI\Http\Response;
use SupportAI\Infrastructure\Persistence\AgentRepository;
use SupportAI\Infrastructure\Persistence\EvalRepository;
use SupportAI\Support\View;
use Throwable;

/**
 * Admin UI for the offline golden-Q&A eval harness: create sets, add cases, run
 * them through the real pipeline, and see scores. Catches quality regressions
 * after you change knowledge, prompts, or models.
 */
final class EvalController
{
    private View $view;

    public function __construct(
        private EvalRepository $evals,
        private EvalRunner $runner,
        private AgentRepository $agents,
    ) {
        $this->view = new View(base_path('admin/views'));
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

    public function index(Request $request): void
    {
        $agentId = (int) $this->agents->findOrFail()['id'];
        $sets = $this->evals->sets($agentId);
        $selectedId = (int) $request->input('set', $sets[0]['id'] ?? 0);
        $selected = $selectedId ? $this->evals->findSet($selectedId) : null;

        $cases = $selected ? $this->evals->cases($selectedId) : [];
        $latest = $selected ? $this->evals->latestRun($selectedId) : null;
        $results = $latest ? $this->evals->results((int) $latest['id']) : [];

        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        Response::html($this->view->render('evals', [
            'title'    => 'Eval harness',
            'active'   => 'evals',
            'sets'     => $sets,
            'selected' => $selected,
            'cases'    => $cases,
            'latest'   => $latest,
            'results'  => $results,
            'flash'    => $flash,
        ]));
    }

    public function createSet(Request $request): void
    {
        $agentId = (int) $this->agents->findOrFail()['id'];
        $name = trim((string) $request->input('name', ''));
        $id = 0;
        if ($name !== '') {
            $id = $this->evals->createSet($agentId, $name);
        }
        Response::redirect('/admin/evals' . ($id ? '?set=' . $id : ''));
    }

    public function deleteSet(Request $request): void
    {
        $this->evals->deleteSet((int) $request->input('id', 0));
        Response::redirect('/admin/evals');
    }

    public function addCase(Request $request): void
    {
        $setId = (int) $request->input('set_id', 0);
        $question = trim((string) $request->input('question', ''));
        $expected = trim((string) $request->input('expected', ''));
        $must = array_values(array_filter(array_map('trim', explode(',', (string) $request->input('must_include', '')))));
        if ($setId && $question !== '') {
            $this->evals->addCase($setId, $question, $expected, $must);
        }
        Response::redirect('/admin/evals?set=' . $setId);
    }

    public function deleteCase(Request $request): void
    {
        $setId = (int) $request->input('set_id', 0);
        $this->evals->deleteCase((int) $request->input('id', 0));
        Response::redirect('/admin/evals?set=' . $setId);
    }

    public function run(Request $request): void
    {
        $setId = (int) $request->input('set_id', 0);
        try {
            $r = $this->runner->run($setId);
            $_SESSION['flash'] = ['type' => 'ok', 'message' => sprintf(
                'Ran %d case(s) — avg score %.0f%%, retrieval hit %.0f%%, grounded %.0f%%.',
                $r['cases'], $r['avg'] * 100, $r['hit'] * 100, $r['grounded'] * 100
            )];
        } catch (Throwable $e) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Run failed: ' . $e->getMessage()];
        }
        Response::redirect('/admin/evals?set=' . $setId);
    }
}
