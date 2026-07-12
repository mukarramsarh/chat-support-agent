<?php

declare(strict_types=1);

namespace SupportAI\Application\Demo;

use SupportAI\Application\Ingestion\IngestionService;
use SupportAI\Infrastructure\Database\Database;
use SupportAI\Infrastructure\Persistence\AgentRepository;
use SupportAI\Infrastructure\Persistence\EvalRepository;
use SupportAI\Infrastructure\Persistence\SettingsRepository;
use SupportAI\Infrastructure\Vector\VectorStoreFactory;
use SupportAI\Support\Logger;
use Throwable;

/**
 * Seeds a ready-to-demo ProcurementHub configuration: bilingual (AR/EN) persona,
 * business knowledge, and an eval set. Run from CLI: `php bin/console demo`.
 * Content is sourced from procurementhub.sa (a Saudi procurement solutions firm,
 * est. 2016, Riyadh).
 */
final class DemoSeeder
{
    public function __construct(
        private AgentRepository $agents,
        private IngestionService $ingestion,
        private EvalRepository $evals,
        private SettingsRepository $settings,
        private Database $db,
        private VectorStoreFactory $vectors,
        private Logger $logger,
    ) {
    }

    /** @return array{docs:int,eval_set:int} */
    public function seed(bool $fresh = true): array
    {
        $agent = $this->agents->findOrFail();
        $agentId = (int) $agent['id'];

        if ($fresh) {
            $this->clearKnowledge($agentId);
        }

        $this->agents->update($agentId, [
            'name'             => 'ProcurementHub Assistant',
            'persona'          => $this->persona(),
            'welcome_message'  => '👋 Welcome to Procurement Hub! How can I help you today? — مرحباً بك في بروكيورمنت هب! كيف يمكنني مساعدتك اليوم؟',
            'fallback_message' => "I'm not certain about that — shall I connect you with our team? / لست متأكداً من ذلك — هل ترغب في توصيلك بفريقنا؟",
        ]);

        $docs = 0;
        foreach ($this->knowledge() as [$title, $content]) {
            try {
                $this->ingestion->ingest($agentId, 'text', ['title' => $title, 'content' => $content]);
                $docs++;
            } catch (Throwable $e) {
                $this->logger->warning('Demo seed: ingest failed', ['title' => $title, 'error' => $e->getMessage()]);
            }
        }

        $setId = $this->seedEvalSet($agentId);

        return ['docs' => $docs, 'eval_set' => $setId];
    }

    private function clearKnowledge(int $agentId): void
    {
        $ids = array_map(
            static fn ($r) => (int) $r['id'],
            $this->db->all('SELECT id FROM chunks WHERE agent_id = :a', ['a' => $agentId])
        );
        if ($ids !== []) {
            try {
                $this->vectors->make()->delete('chunks', $ids);
            } catch (Throwable) {
            }
        }
        $this->db->run('DELETE FROM documents WHERE agent_id = :a', ['a' => $agentId]);
        $this->db->run('DELETE FROM answer_cache WHERE agent_id = :a', ['a' => $agentId]);
        $this->settings->bumpKbVersion();
    }

    private function persona(): string
    {
        return 'You are the official virtual support assistant for Procurement Hub (بروكيورمنت هب), a Saudi '
            . 'procurement solutions and advisory firm established in 2016 and headquartered in Riyadh. Help '
            . 'visitors understand the company\'s services, professional products, and how to get in touch. Be '
            . 'professional, concise and warm. Answer strictly from the provided knowledge; if something is not '
            . 'covered, say you do not have that information and offer to connect them with the team at '
            . 'Info@procurementhub.sa or +966 55 524 5527. Always reply in the same language the user writes in '
            . '(Arabic or English).';
    }

    /** @return array<int,array{0:string,1:string}> */
    private function knowledge(): array
    {
        return [
            ['About Procurement Hub',
                'Procurement Hub is a Saudi Arabian company established in 2016, specializing in procurement '
                . 'solutions and contract management. Its mission is "Transforming Procurement into a Strategic '
                . 'Engine for Value Creation." The company provides end-to-end procurement solutions across the '
                . 'entire lifecycle — from strategy and operating-model design through execution and continuous '
                . 'optimization — helping organizations strengthen governance, enhance operational efficiency, and '
                . 'achieve sustainable value. Procurement Hub is headquartered in Riyadh, Saudi Arabia.'],

            ['Services',
                'Procurement Hub offers four core service categories: (1) Procurement Advisory — strategy '
                . 'development, category management, operating-model design, and governance frameworks. '
                . '(2) Managed Procurement Operations — end-to-end sourcing support, contract lifecycle management, '
                . 'and supplier onboarding and performance management. (3) Procurement Transformation — process '
                . 'redesign, capability development, and performance-improvement initiatives. (4) Digital '
                . 'Procurement Solutions — a Vendor Hub platform plus analytics and executive dashboards.'],

            ['Professional Products & Operations Center',
                'Procurement Hub offers nine structured professional products addressing specific procurement '
                . 'challenges: Monitor, Plan, Diagnostics, Local, Optimize, Govern, Secure, Costs, and Customize. '
                . 'It also provides procurement maturity assessments, local-content support and development, and '
                . 'the Saudi Procurement Operations Center — a centralized, scalable operating model offering '
                . 'structured governance, specialized teams, and advanced digital tools.'],

            ['Contact Procurement Hub',
                'You can contact Procurement Hub at its office in Riyadh, Al Wadi District, Northern Ring Road '
                . 'Exit 6, Office 24, Saudi Arabia. Phone: +966 55 524 5527 or 011 266 6628. Email: '
                . 'Info@procurementhub.sa. Website: procurementhub.sa. Procurement Hub is active on LinkedIn, '
                . 'Instagram, X (Twitter), and YouTube.'],

            ['نبذة عن بروكيورمنت هب',
                'بروكيورمنت هب شركة سعودية تأسست عام 2016 متخصصة في حلول المشتريات وإدارة العقود. رسالتها: '
                . '"تحويل المشتريات إلى محرك استراتيجي لخلق القيمة". تقدّم الشركة حلول مشتريات متكاملة عبر دورة '
                . 'الحياة الكاملة — من تصميم الاستراتيجية ونموذج التشغيل وحتى التنفيذ والتحسين المستمر — لمساعدة '
                . 'المؤسسات على تعزيز الحوكمة ورفع الكفاءة التشغيلية وتحقيق قيمة مستدامة. يقع مقر بروكيورمنت هب في '
                . 'الرياض بالمملكة العربية السعودية.'],

            ['الخدمات',
                'تقدّم بروكيورمنت هب أربع فئات رئيسية من الخدمات: (١) الاستشارات في المشتريات — تطوير الاستراتيجية '
                . 'وإدارة الفئات وتصميم نموذج التشغيل وأطر الحوكمة. (٢) عمليات المشتريات المُدارة — دعم التوريد '
                . 'الشامل وإدارة دورة حياة العقود وتأهيل الموردين وإدارة أدائهم. (٣) تحويل المشتريات — إعادة تصميم '
                . 'العمليات وبناء القدرات وتحسين الأداء. (٤) حلول المشتريات الرقمية — منصة مركز الموردين إضافة إلى '
                . 'التحليلات ولوحات المعلومات التنفيذية.'],

            ['المنتجات ومركز العمليات',
                'تقدّم بروكيورمنت هب تسعة منتجات احترافية لمعالجة تحديات المشتريات: Monitor وPlan وDiagnostics '
                . 'وLocal وOptimize وGovern وSecure وCosts وCustomize. كما تقدّم تقييمات نضج المشتريات، ودعم وتطوير '
                . 'المحتوى المحلي، ومركز العمليات السعودي للمشتريات — نموذج تشغيل مركزي قابل للتوسّع بحوكمة منظمة '
                . 'وفرق متخصصة وأدوات رقمية متقدمة.'],

            ['التواصل مع بروكيورمنت هب',
                'يمكنكم التواصل مع بروكيورمنت هب في مكتبها بالرياض، حي الوادي، مخرج ٦ الطريق الدائري الشمالي، مكتب '
                . '٢٤، المملكة العربية السعودية. الهاتف: 0112666628 أو +966555245527. البريد الإلكتروني: '
                . 'Info@procurementhub.sa. الموقع: procurementhub.sa.'],
        ];
    }

    private function seedEvalSet(int $agentId): int
    {
        $setId = $this->evals->createSet($agentId, 'ProcurementHub QA (AR/EN)');
        $cases = [
            ['When was Procurement Hub established?', 'In 2016.', ['2016']],
            ['Where is Procurement Hub located?', 'Riyadh, Saudi Arabia.', ['Riyadh']],
            ['What services does Procurement Hub offer?', 'Advisory, managed operations, transformation, digital.', ['procurement']],
            ['How can I contact Procurement Hub?', 'Info@procurementhub.sa / +966 55 524 5527.', ['procurementhub.sa']],
            ['List the professional products of Procurement Hub.', 'Monitor, Plan, Diagnostics, Local, Optimize, Govern, Secure, Costs, Customize.', ['Monitor']],
            ['متى تأسست بروكيورمنت هب؟', 'عام 2016.', ['2016']],
            ['أين يقع مقر بروكيورمنت هب؟', 'الرياض، المملكة العربية السعودية.', ['الرياض']],
            ['ما هي خدمات بروكيورمنت هب؟', 'استشارات وعمليات مُدارة وتحويل وحلول رقمية.', ['المشتريات']],
        ];
        foreach ($cases as [$q, $expected, $must]) {
            $this->evals->addCase($setId, $q, $expected, $must);
        }
        return $setId;
    }
}
