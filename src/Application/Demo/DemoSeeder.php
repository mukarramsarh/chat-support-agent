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
 * Seeds a production-ready ProcurementHub configuration: a bilingual (AR/EN)
 * master prompt, comprehensive business knowledge, general Saudi procurement /
 * local-content regulatory context (framed as guidance, not legal advice), and
 * an eval set. Run from CLI: `php bin/console demo`.
 *
 * Sources: procurementhub.sa; Saudi Ministry of Finance (Government Tenders and
 * Procurement Law); the Local Content & Government Procurement Authority (LCGPA);
 * the Etimad platform.
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
            'welcome_message'  => '👋 Welcome to Procurement Hub! Ask me about our services, products, or Saudi procurement & local-content rules. — مرحباً بك في بروكيورمنت هب! اسألني عن خدماتنا ومنتجاتنا أو أنظمة المشتريات والمحتوى المحلي في السعودية.',
            'fallback_message' => "I don't have that detail — shall I connect you with our team? / لا تتوفر لديّ هذه المعلومة — هل ترغب في توصيلك بفريقنا؟",
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
        return <<<'PROMPT'
You are the official virtual assistant for Procurement Hub (بروكيورمنت هب), a Saudi procurement
solutions and advisory firm established in 2016 and headquartered in Riyadh, Saudi Arabia.

Your role:
- Help visitors understand Procurement Hub's services, professional products (Monitor, Plan,
  Diagnostics, Local, Optimize, Govern, Secure, Costs, Customize), the Saudi Procurement
  Operations Center (SPOC), and how to engage the team.
- Provide GENERAL, factual information about Saudi government procurement and local-content
  regulation — the Government Tenders and Procurement Law (GTPL), the Etimad platform, and the
  Local Content and Government Procurement Authority (LCGPA) — to help clients understand the
  landscape Procurement Hub works in, and how Procurement Hub can support compliance.

Rules:
- Answer strictly from the provided KNOWLEDGE. If a detail is not covered, say you don't have it
  and offer to connect the visitor with the Procurement Hub team (Info@procurementhub.sa,
  +966 55 524 5527).
- Regulatory information is GENERAL guidance only — NOT legal advice. For binding, current
  requirements always refer users to the official sources (Ministry of Finance, LCGPA, the Etimad
  platform) and recommend consulting Procurement Hub's experts for their specific situation.
- Be professional, concise, and warm. Never invent facts, figures, deadlines, percentages, or
  legal thresholds. Cite the KNOWLEDGE items you use as [n].
- Always reply in the SAME language the user writes in (Arabic → Arabic, English → English).
PROMPT;
    }

    /** @return array<int,array{0:string,1:string}> */
    private function knowledge(): array
    {
        return array_merge($this->companyKnowledge(), $this->regulatoryKnowledge());
    }

    /** @return array<int,array{0:string,1:string}> */
    private function companyKnowledge(): array
    {
        return [
            ['About Procurement Hub',
                'Procurement Hub is a Saudi company established in 2016, specializing in procurement '
                . 'solutions and contract management. Its mission is "Transforming Procurement into a '
                . 'Strategic Engine for Value Creation." It delivers end-to-end procurement solutions across '
                . 'the full lifecycle — from strategy and operating-model design through execution and '
                . 'continuous optimization — helping organizations strengthen governance, raise operational '
                . 'efficiency, and achieve sustainable value. Headquartered in Riyadh, Saudi Arabia. '
                . 'Website: procurementhub.sa. Main sections: Home, About Us, Products & Services, SPOC, '
                . 'Insights/Knowledge Center, Careers, Contact Us. The site is available in English and Arabic.'],

            ['Procurement Advisory Services',
                'Procurement Hub\'s Advisory services include: Procurement Assessment (evaluating procurement '
                . 'maturity across governance, processes and performance against leading practices); Local '
                . 'Content Support & Development (aligning with national localization requirements and developing '
                . 'supplier ecosystems); Category Management (strategic spend-category management using market '
                . 'intelligence and demand analysis); Supplier Relationship Management; Strategic Sourcing; '
                . 'Procurement Processes & Procedures Development; Procurement Department Operating Model design; '
                . 'Procurement Optimization; and Cost Management & Expenditure Analysis.'],

            ['Managed Operations & Transformation',
                'Managed Procurement Operations: end-to-end sourcing support, contract lifecycle management, '
                . 'supplier onboarding and performance management, purchasing on behalf of non-operational '
                . 'entities, and outsourcing with external resources. Procurement Transformation: process '
                . 'redesign and workflow optimization, capability development and team training, and '
                . 'performance-improvement initiatives.'],

            ['Digital Solutions & Saudi Procurement Operations Center (SPOC)',
                'Digital Solutions: a Vendor Hub platform, procurement analytics and executive dashboards, and '
                . 'performance analytics and reporting tools. The Saudi Procurement Operations Center (SPOC) is '
                . 'a centralized, scalable procurement operating model that offers structured governance '
                . 'frameworks, specialized teams with defined roles, advanced digital tools, standardized '
                . 'procedures across clients, and consistent performance standards to strengthen compliance.'],

            ['Professional Products (Monitor, Plan, Diagnostics, Local, Optimize, Govern, Secure, Costs, Customize)',
                'Procurement Hub offers nine structured professional products, each built on practical '
                . 'implementation expertise and global leading practices: Monitor (performance tracking and '
                . 'oversight); Plan (strategic procurement planning and forecasting); Diagnostics (assessment '
                . 'and analysis tools); Local (local-content management and compliance); Optimize (efficiency '
                . 'and performance enhancement); Govern (governance frameworks and controls); Secure (risk '
                . 'management and security protocols); Costs (cost management and financial analysis); and '
                . 'Customize (tailored solution configuration).'],

            ['Contact Procurement Hub',
                'You can reach Procurement Hub at its office in Riyadh, Al Wadi District, Northern Ring Road '
                . 'Exit 6, Office 24, Saudi Arabia. Email: Info@procurementhub.sa. Phone: +966 55 524 5527 or '
                . '011 266 6628. WhatsApp: +966 55 524 5527. Website: procurementhub.sa. There is also a '
                . 'Careers section for open opportunities.'],

            ['نبذة عن بروكيورمنت هب',
                'بروكيورمنت هب شركة سعودية تأسست عام 2016 متخصصة في حلول المشتريات وإدارة العقود. رسالتها: '
                . '"تحويل المشتريات إلى محرك استراتيجي لخلق القيمة". تقدّم حلولاً متكاملة عبر دورة الحياة الكاملة '
                . 'للمشتريات — من تصميم الاستراتيجية ونموذج التشغيل وحتى التنفيذ والتحسين المستمر — لمساعدة '
                . 'المؤسسات على تعزيز الحوكمة ورفع الكفاءة التشغيلية وتحقيق قيمة مستدامة. يقع مقرها في الرياض. '
                . 'الموقع: procurementhub.sa، ويتوفر بالعربية والإنجليزية.'],

            ['خدمات بروكيورمنت هب',
                'خدمات الاستشارات: تقييم نضج المشتريات، ودعم وتطوير المحتوى المحلي، وإدارة فئات الإنفاق، وإدارة '
                . 'علاقات الموردين، والتوريد الاستراتيجي، وتطوير إجراءات المشتريات، وتصميم نموذج تشغيل إدارة '
                . 'المشتريات، وتحسين المشتريات، وإدارة التكاليف وتحليل الإنفاق. عمليات المشتريات المُدارة: دعم '
                . 'التوريد الشامل، وإدارة دورة حياة العقود، وتأهيل الموردين وإدارة أدائهم، والشراء نيابةً عن '
                . 'الجهات. التحويل: إعادة تصميم العمليات وبناء القدرات وتحسين الأداء. الحلول الرقمية: منصة مركز '
                . 'الموردين، والتحليلات، ولوحات المعلومات التنفيذية.'],

            ['منتجات ومركز العمليات (SPOC)',
                'تقدّم بروكيورمنت هب تسعة منتجات احترافية: Monitor (المتابعة والرقابة)، Plan (التخطيط والتنبؤ)، '
                . 'Diagnostics (التقييم والتحليل)، Local (إدارة المحتوى المحلي والامتثال)، Optimize (تحسين '
                . 'الكفاءة)، Govern (أطر الحوكمة والضوابط)، Secure (إدارة المخاطر)، Costs (إدارة التكاليف)، '
                . 'Customize (حلول مخصّصة). ومركز العمليات السعودي للمشتريات (SPOC) نموذج تشغيل مركزي قابل '
                . 'للتوسّع بحوكمة منظمة وفرق متخصصة وأدوات رقمية متقدمة وإجراءات موحّدة لتعزيز الامتثال.'],

            ['التواصل مع بروكيورمنت هب',
                'يمكنكم التواصل مع بروكيورمنت هب في مكتبها بالرياض، حي الوادي، مخرج ٦ الطريق الدائري الشمالي، '
                . 'مكتب ٢٤. البريد الإلكتروني: Info@procurementhub.sa. الهاتف: +966555245527 أو 0112666628. '
                . 'واتساب: +966555245527. الموقع: procurementhub.sa.'],
        ];
    }

    /** @return array<int,array{0:string,1:string}> */
    private function regulatoryKnowledge(): array
    {
        return [
            ['Government Tenders and Procurement Law (GTPL)',
                'GENERAL GUIDANCE (not legal advice). The Government Tenders and Procurement Law (GTPL) is the '
                . 'main law regulating procurement by Saudi government entities. The amended GTPL was enacted in '
                . '2019 and is overseen by the Ministry of Finance. It governs tenders and commercial agreements '
                . 'between government entities and suppliers, aiming to simplify procedures, increase '
                . 'transparency and fair competition, protect public funds, and support the national economy. It '
                . 'is supported by Implementing Regulations. For binding, current requirements, consult the '
                . 'Ministry of Finance (mof.gov.sa) and Procurement Hub\'s experts.'],

            ['The Etimad Platform (منصة اعتماد)',
                'GENERAL GUIDANCE (not legal advice). Etimad is the Kingdom\'s electronic government procurement '
                . 'platform, launched by the Ministry of Finance in 2018 to consolidate and streamline bidding '
                . 'and procurement across government sectors. As a general rule, government procurements must be '
                . 'published and conducted through Etimad. Suppliers register on the platform to view and bid on '
                . 'government tenders. Procurement Hub helps organizations operate effectively within this '
                . 'digital environment.'],

            ['Local Content & the LCGPA',
                'GENERAL GUIDANCE (not legal advice). The Local Content and Government Procurement Authority '
                . '(LCGPA) sets, monitors and catalogues minimum local-content requirements for government '
                . 'contractors. Suppliers are expected to document their local-content levels and meet sector '
                . 'thresholds; many government tenders require a valid Local Content Certificate. Failing to '
                . 'meet local-content requirements can lead to disqualification. Local-content requirements have '
                . 'also been extended to certain state-owned entities. Procurement Hub\'s Local Content Support '
                . '& Development and its "Local" product help organizations align with these requirements and '
                . 'build local supplier ecosystems. For current thresholds and certification, refer to the '
                . 'LCGPA (lcgpa.gov.sa).'],

            ['Price Preferences for Local Suppliers & SMEs',
                'GENERAL GUIDANCE (not legal advice). Saudi procurement rules give preferences to local '
                . 'suppliers and products. Preference is given to Saudi individuals, establishments and '
                . 'majority Saudi-owned suppliers, and to products of Saudi origin. Under the preference '
                . 'regulations, small and medium enterprises (SMEs) majority-owned by Saudi nationals may '
                . 'receive around a 10% price advantage in evaluation, and companies listed on the Saudi capital '
                . 'market may receive around a 5% price advantage. Exact percentages and eligibility are defined '
                . 'by the official regulations — confirm current figures with the Ministry of Finance and LCGPA.'],

            ['Mandatory National-Products List',
                'GENERAL GUIDANCE (not legal advice). LCGPA maintains a Mandatory List of national products, '
                . 'compiled from manufacturers\' requests. Where a product appears on this list, government '
                . 'entities are required to purchase the national product. A separate Mandatory List also '
                . 'applies to certain state-owned companies. Procurement Hub helps organizations track and '
                . 'comply with these lists as part of local-content compliance.'],

            ['How Procurement Hub supports Saudi procurement compliance',
                'Procurement Hub helps public and private organizations navigate Saudi procurement and '
                . 'local-content regulations: assessing local-content maturity, preparing for Local Content '
                . 'Certificates, structuring category and sourcing strategies that respect preference rules, and '
                . 'operating efficiently on Etimad. Its advisory services, the "Local" and "Govern" products, '
                . 'and SPOC provide governance and compliance support. Note: Procurement Hub provides '
                . 'professional guidance; official, binding requirements come from the Ministry of Finance, '
                . 'LCGPA, and the Etimad platform.'],

            ['نظام المنافسات والمشتريات الحكومية',
                'إرشاد عام (وليس استشارة قانونية). نظام المنافسات والمشتريات الحكومية هو النظام الرئيسي المنظّم '
                . 'لمشتريات الجهات الحكومية في المملكة، وقد صدر النظام المُحدّث عام 2019 وتشرف عليه وزارة المالية. '
                . 'ينظّم المنافسات والتعاقدات بين الجهات الحكومية والموردين بهدف تبسيط الإجراءات وتعزيز الشفافية '
                . 'والمنافسة العادلة وحماية المال العام ودعم الاقتصاد الوطني، وله لائحة تنفيذية. للاطلاع على '
                . 'المتطلبات المُلزمة والحالية يُرجى الرجوع إلى وزارة المالية وخبراء بروكيورمنت هب.'],

            ['منصة اعتماد',
                'إرشاد عام (وليس استشارة قانونية). "اعتماد" هي المنصة الإلكترونية الحكومية للمشتريات، أطلقتها '
                . 'وزارة المالية عام 2018 لتوحيد وتسهيل عمليات المنافسات والمشتريات في القطاعات الحكومية. وكقاعدة '
                . 'عامة تُطرح المشتريات الحكومية وتُدار عبر منصة اعتماد، ويسجّل الموردون فيها للاطلاع على '
                . 'المنافسات والتقدّم إليها. وتساعد بروكيورمنت هب المؤسسات على العمل بكفاءة ضمن هذه المنظومة.'],

            ['المحتوى المحلي وهيئة المحتوى المحلي والمشتريات الحكومية',
                'إرشاد عام (وليس استشارة قانونية). تحدّد هيئة المحتوى المحلي والمشتريات الحكومية الحد الأدنى '
                . 'لمتطلبات المحتوى المحلي وتراقبها للموردين الحكوميين. ويُتوقع من الموردين توثيق نسب المحتوى '
                . 'المحلي واستيفاء متطلبات القطاع، وكثير من المنافسات تتطلب شهادة محتوى محلي سارية. وقد يؤدي عدم '
                . 'الاستيفاء إلى الاستبعاد. وتساعد خدمات بروكيورمنت هب لدعم وتطوير المحتوى المحلي ومنتج "Local" '
                . 'المؤسسات على الالتزام. للاطلاع على النسب الحالية يُرجى الرجوع إلى الهيئة (lcgpa.gov.sa).'],

            ['أفضليات الأسعار للموردين المحليين والمنشآت الصغيرة والمتوسطة',
                'إرشاد عام (وليس استشارة قانونية). تمنح أنظمة المشتريات أفضليات للموردين والمنتجات المحلية، '
                . 'وتُعطى الأفضلية للأفراد والمنشآت السعودية والموردين المملوكين لسعوديين بأغلبية، وللمنتجات ذات '
                . 'المنشأ الوطني. وبحسب لائحة الأفضليات قد تحصل المنشآت الصغيرة والمتوسطة المملوكة لسعوديين '
                . 'بأغلبية على أفضلية سعرية تقارب 10%، وقد تحصل الشركات المدرجة في السوق المالية على أفضلية تقارب '
                . '5%. وتُحدَّد النسب والأهلية بدقة في الأنظمة الرسمية — يُرجى تأكيد الأرقام الحالية مع وزارة '
                . 'المالية والهيئة.'],
        ];
    }

    private function seedEvalSet(int $agentId): int
    {
        $name = 'ProcurementHub QA (AR/EN)';

        // Replace (don't duplicate) the set on re-seed.
        foreach ($this->evals->sets($agentId) as $existing) {
            if (($existing['name'] ?? '') === $name) {
                $this->evals->deleteSet((int) $existing['id']);
            }
        }

        $setId = $this->evals->createSet($agentId, $name);
        $cases = [
            // Company
            ['When was Procurement Hub established and where is it based?', 'In 2016, in Riyadh, Saudi Arabia.', ['2016', 'Riyadh']],
            ['What advisory services does Procurement Hub offer?', 'Assessment, local content, category management, sourcing, etc.', ['procurement']],
            ['List the nine professional products of Procurement Hub.', 'Monitor, Plan, Diagnostics, Local, Optimize, Govern, Secure, Costs, Customize.', ['Monitor', 'Customize']],
            ['What is SPOC?', 'The Saudi Procurement Operations Center.', ['operations']],
            ['How can I contact Procurement Hub?', 'Info@procurementhub.sa / +966 55 524 5527.', ['procurementhub.sa']],
            // Regulatory
            ['What is the GTPL in Saudi Arabia?', 'The Government Tenders and Procurement Law.', ['procurement']],
            ['What is the Etimad platform?', 'The government e-procurement platform.', ['Etimad']],
            ['What price preference can Saudi SMEs get in government tenders?', 'Around 10%.', ['10%']],
            ['What does the LCGPA do?', 'Sets and monitors local-content requirements.', ['local content']],
            // Arabic
            ['متى تأسست بروكيورمنت هب وأين مقرها؟', 'عام 2016 في الرياض.', ['2016', 'الرياض']],
            ['ما هي منتجات بروكيورمنت هب؟', 'تسعة منتجات احترافية.', ['Monitor']],
            ['ما هو نظام المنافسات والمشتريات الحكومية؟', 'النظام المنظّم للمشتريات الحكومية.', ['المشتريات']],
            ['ما هي منصة اعتماد؟', 'المنصة الإلكترونية الحكومية للمشتريات.', ['اعتماد']],
            ['كيف تساعد بروكيورمنت هب في المحتوى المحلي؟', 'عبر خدمات دعم وتطوير المحتوى المحلي.', ['المحتوى المحلي']],
        ];
        foreach ($cases as [$q, $expected, $must]) {
            $this->evals->addCase($setId, $q, $expected, $must);
        }
        return $setId;
    }
}
