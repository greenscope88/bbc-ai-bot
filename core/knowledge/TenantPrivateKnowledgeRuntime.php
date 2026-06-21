<?php

declare(strict_types=1);



require_once __DIR__ . DIRECTORY_SEPARATOR . 'TenantPrivateKnowledgeProviderInterface.php';

require_once __DIR__ . DIRECTORY_SEPARATOR . 'GcsTenantPrivateKnowledgeProvider.php';

require_once __DIR__ . DIRECTORY_SEPARATOR . 'KnowledgeQueryFieldResolver.php';

require_once __DIR__ . DIRECTORY_SEPARATOR . 'KnowledgeResponseComposer.php';

require_once __DIR__ . DIRECTORY_SEPARATOR . 'ServiceQaMatcher.php';

require_once __DIR__ . DIRECTORY_SEPARATOR . 'KnowledgeFallbackResolver.php';



/**

 * Tenant private knowledge runtime (Phase 9-C-2B-2A company_profile + 9-C-2B-3 service_qa).

 *

 * Runtime resolves grounded facts only; composers produce final LINE copy.

 */

final class TenantPrivateKnowledgeRuntime

{

    public const QUERY_TYPE_SERVICE_QA = 'service_qa';



    private TenantPrivateKnowledgeProviderInterface $provider;

    private KnowledgeQueryFieldResolver $fieldResolver;

    private KnowledgeResponseComposer $responseComposer;

    private ServiceQaMatcher $serviceQaMatcher;

    private KnowledgeFallbackResolver $fallbackResolver;



    public function __construct(

        ?TenantPrivateKnowledgeProviderInterface $provider = null,

        ?KnowledgeQueryFieldResolver $fieldResolver = null,

        ?KnowledgeResponseComposer $responseComposer = null,

        ?string $tenantSno = null,

        ?ServiceQaMatcher $serviceQaMatcher = null,

        ?KnowledgeFallbackResolver $fallbackResolver = null

    ) {

        $resolvedTenantSno = trim((string) ($tenantSno ?? ''));

        if ($provider === null) {

            if ($resolvedTenantSno === '') {

                throw new InvalidArgumentException('tenant_sno is required when provider is not injected.');

            }

            $provider = new GcsTenantPrivateKnowledgeProvider($resolvedTenantSno);

        }



        $this->provider = $provider;

        $this->fieldResolver = $fieldResolver ?? new KnowledgeQueryFieldResolver();

        $this->responseComposer = $responseComposer ?? new KnowledgeResponseComposer(static function (string $poolKey, int $count): int {

            return 0;

        });

        $this->serviceQaMatcher = $serviceQaMatcher ?? new ServiceQaMatcher();

        $this->fallbackResolver = $fallbackResolver ?? new KnowledgeFallbackResolver();

    }



    /**

     * @param array{tenant_key?: string|null, company_name?: string|null} $context

     * @return array{reply_text: string, grounded: bool, query_type: ?string, final_route: string, qa_id?: string|null, fallback_layer?: string|null}

     */

    public function handle(string $message, array $context = []): array

    {

        $query = $this->fieldResolver->resolve($message);

        if ($query !== null) {

            return $this->handleCompanyProfileQuery($message, $query, $context);

        }



        $qaMatch = $this->serviceQaMatcher->match($message, $this->extractItems($this->provider->fetchServiceQaDocument()));

        if ($qaMatch !== null) {

            return $this->buildResult(

                $this->responseComposer->composeServiceQaReply(

                    (string) ($qaMatch['grounded_fact'] ?? ''),

                    isset($qaMatch['category']) ? (string) $qaMatch['category'] : null

                ),

                true,

                self::QUERY_TYPE_SERVICE_QA,

                'phase_9c2b3_qa_knowledge_runtime',

                isset($qaMatch['qa_id']) ? (string) $qaMatch['qa_id'] : null,

                null

            );

        }



        $companyName = $this->resolveCompanyName($context);

        $fallback = $this->fallbackResolver->resolve(

            $message,

            isset($context['tenant_key']) ? (string) $context['tenant_key'] : null,

            $companyName

        );



        return $this->buildResult(

            (string) ($fallback['reply_text'] ?? ''),

            (bool) ($fallback['grounded'] ?? false),

            null,

            (string) ($fallback['final_route'] ?? 'phase_9c2b3_knowledge_human_service'),

            null,

            (string) ($fallback['fallback_layer'] ?? 'human_service')

        );

    }



    /**

     * @param array{type: string} $query

     * @param array{tenant_key?: string|null, company_name?: string|null} $context

     * @return array{reply_text: string, grounded: bool, query_type: ?string, final_route: string, qa_id?: string|null, fallback_layer?: string|null}

     */

    private function handleCompanyProfileQuery(string $message, array $query, array $context): array

    {

        unset($message, $context);



        $field = (string) ($query['type'] ?? '');

        $profile = $this->extractProfile($this->provider->fetchCompanyProfileDocument());

        if ($profile === []) {

            return $this->buildResult(

                $this->responseComposer->composeMissingProfileReply(),

                false,

                $field,

                'phase_9c2b2a_knowledge_runtime_missing_profile',

                null,

                null

            );

        }



        $companyName = isset($profile['company_name']) ? trim((string) $profile['company_name']) : '';

        $value = isset($profile[$field]) ? trim((string) $profile[$field]) : '';

        if ($value === '') {

            return $this->buildResult(

                $this->responseComposer->composeEmptyFieldReply(),

                false,

                $field,

                'phase_9c2b2a_knowledge_runtime_empty_field',

                null,

                null

            );

        }



        return $this->buildResult(

            $this->responseComposer->composeCompanyProfileReply($field, $companyName, $value),

            true,

            $field,

            'phase_9c2b2a_knowledge_runtime',

            null,

            null

        );

    }



    /**

     * @param array{tenant_key?: string|null, company_name?: string|null} $context

     */

    private function resolveCompanyName(array $context): ?string

    {

        $companyName = trim((string) ($context['company_name'] ?? ''));

        if ($companyName !== '') {

            return $companyName;

        }



        $profile = $this->extractProfile($this->provider->fetchCompanyProfileDocument());

        $fromProfile = isset($profile['company_name']) ? trim((string) $profile['company_name']) : '';

        if ($fromProfile !== '') {

            return $fromProfile;

        }



        return null;

    }



    /**

     * @param array<string, mixed> $document

     * @return array<string, mixed>

     */

    private function extractProfile(array $document): array

    {

        if (!isset($document['profile']) || !is_array($document['profile'])) {

            return [];

        }



        return $document['profile'];

    }



    /**

     * @param array<string, mixed> $document

     * @return list<array<string, mixed>>

     */

    private function extractItems(array $document): array

    {

        if (!isset($document['items']) || !is_array($document['items'])) {

            return [];

        }



        $items = [];

        foreach ($document['items'] as $item) {

            if (is_array($item)) {

                $items[] = $item;

            }

        }



        return $items;

    }



    /**

     * @return array{reply_text: string, grounded: bool, query_type: ?string, final_route: string, qa_id?: string|null, fallback_layer?: string|null}

     */

    private function buildResult(

        string $replyText,

        bool $grounded,

        ?string $queryType,

        string $finalRoute,

        ?string $qaId,

        ?string $fallbackLayer

    ): array {

        $result = [

            'reply_text' => $replyText,

            'grounded' => $grounded,

            'query_type' => $queryType,

            'final_route' => $finalRoute,

        ];



        if ($qaId !== null && $qaId !== '') {

            $result['qa_id'] = $qaId;

        }



        if ($fallbackLayer !== null && $fallbackLayer !== '') {

            $result['fallback_layer'] = $fallbackLayer;

        }



        return $result;

    }

}


