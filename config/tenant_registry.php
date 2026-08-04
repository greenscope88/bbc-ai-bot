<?php
declare(strict_types=1);

return array (
  'schema_version' => 1,
  'global' => 
  array (
    'hybrid_search_master_enabled' => true,
    'tour_prompt_master_enabled' => true,
  ),
  'tenants' => 
  array (
    'travel_a' => 
    array (
      'credential_env_prefix' => 'travel_a',
      'display_name' => 'Staging 旅行社 A（BBC AI 驗收基線）',
      'line_channel_id' => 'Ufcedee37a93230a802c30b138f6228f8',
      'sno' => 'e1fd133c7e8e45a1',
      'depID' => 888,
      'storeNo' => 6290,
      'store_uid' => 6290,
      'provider_id_no' => 102,
      'status' => 'enabled',
      'profile' => 
      array (
        'company_name' => '旅行社客服',
        'ai_tone' => '親切',
        'travel_specialties' => '綜合旅遊',
        'price_catalog_json' => '{}',
      ),
      'features' => 
      array (
        'tour_prompt' => true,
        'hybrid_search' => true,
        'fixed_formatter' => true,
        'bats_runtime' => false,
        'aiu_authoritative' => false,
        'grounding_authoritative' => false,
      ),
      'gemini_policy' => 
      array (
        'tone' => 'travel_assistant',
        'allow_fixed_formatter' => true,
        'allow_gemini_rewrite' => false,
      ),
      'source_policy' => 
      array (
        'host_b_enabled' => true,
        'search_client' => 'gateway_php',
      ),
    ),
    'travel_b' => 
    array (
      'created_for' => 'travel_b',
      'credential_env_prefix' => 'travel_b',
      'display_name' => '旅行蜜優惠',
      'line_channel_id' => 'Uc29debdbf97e5e3aa050a6f54cf32091',
      'sno' => '5f99b8d665e8444d',
      'depID' => 888,
      'storeNo' => 6180,
      'store_uid' => 6180,
      'provider_id_no' => 101,
      'status' => 'enabled',
      'onboarding_notes' => 
      array (
        'onboarding_required' => true,
        'created_for' => 'travel_b',
        'phase' => '2A_stage_6',
        'pending_fields' => 
        array (
          0 => 'line_channel_id',
          1 => 'line_channel_secret',
          2 => 'line_channel_access_token',
        ),
        'features_locked_off' => 
        array (
          0 => 'tour_prompt',
          1 => 'hybrid_search',
          2 => 'fixed_formatter',
        ),
        'checklist_doc' => 'docs/TENANT_TRAVEL_B_ONBOARDING_CHECKLIST.md',
      ),
      'profile' => 
      array (
        'company_name' => '旅行社 B 客服（staging）',
        'ai_tone' => '親切',
        'travel_specialties' => '綜合旅遊',
        'price_catalog_json' => '{}',
      ),
      'features' => 
      array (
        'tour_prompt' => true,
        'hybrid_search' => true,
        'fixed_formatter' => true,
        'bats_runtime' => true,
        'aiu_authoritative' => true,
        'grounding_authoritative' => true,
      ),
      'gemini_policy' => 
      array (
        'tone' => 'travel_assistant',
        'allow_fixed_formatter' => true,
        'allow_gemini_rewrite' => false,
      ),
      'source_policy' => 
      array (
        'host_b_enabled' => true,
        'search_client' => 'gateway_php',
      ),
    ),
    'travel_d' => 
    array (
      'credential_env_prefix' => 'travel_d',
      'display_name' => '家樂福旅行社 總公司',
      'line_channel_id' => 'Uf76e61279fd9fd5f8e0274bbd59f7f7c',
      'sno' => '5fecdf66e9224bee',
      'depID' => 888,
      'storeNo' => 6355,
      'store_uid' => 6355,
      'provider_id_no' => 0,
      'status' => 'enabled',
      'profile' => 
      array (
        'company_name' => '家樂福旅行社 總公司',
        'ai_tone' => '親切',
        'travel_specialties' => '綜合旅遊',
        'price_catalog_json' => '{}',
      ),
      'features' => 
      array (
        'tour_prompt' => true,
        'hybrid_search' => true,
        'fixed_formatter' => true,
        'bats_runtime' => true,
        'aiu_authoritative' => true,
        'grounding_authoritative' => false,
      ),
      'gemini_policy' => 
      array (
        'tone' => 'travel_assistant',
        'allow_fixed_formatter' => true,
        'allow_gemini_rewrite' => false,
      ),
      'source_policy' => 
      array (
        'host_b_enabled' => true,
        'search_client' => 'gateway_php',
      ),
    ),
    'travel_c' => 
    array (
      'credential_env_prefix' => 'travel_c',
      'display_name' => '旅行社 C（停用占位）',
      'line_channel_id' => 'U_PLACEHOLDER_TRAVEL_C_CHANNEL',
      'sno' => '00000000-0000-4000-8000-0000000000c1',
      'depID' => 0,
      'storeNo' => 0,
      'store_uid' => 0,
      'provider_id_no' => 0,
      'status' => 'disabled',
      'profile' => 
      array (
        'company_name' => '旅行社 C 客服',
        'ai_tone' => '親切',
        'travel_specialties' => '綜合旅遊',
        'price_catalog_json' => '{}',
      ),
      'features' => 
      array (
        'tour_prompt' => false,
        'hybrid_search' => false,
        'fixed_formatter' => false,
        'bats_runtime' => false,
        'aiu_authoritative' => false,
        'grounding_authoritative' => false,
      ),
      'gemini_policy' => 
      array (
        'tone' => 'travel_assistant',
        'allow_fixed_formatter' => false,
        'allow_gemini_rewrite' => false,
      ),
      'source_policy' => 
      array (
        'host_b_enabled' => false,
        'search_client' => 'gateway_php',
      ),
    ),
    'travel_7fd421d98eab845b' => 
    array (
      'credential_env_prefix' => 'travel_7fd421d98eab845b',
      'display_name' => '大愛旅行社有限公司',
      'line_channel_id' => 'Ubc6e35769ebae9fa87b28922f29c8495',
      'sno' => '7fd421d98eab845b',
      'depID' => 888,
      'storeNo' => 187,
      'store_uid' => 187,
      'provider_id_no' => 0,
      'status' => 'enabled',
      'profile' => 
      array (
        'company_name' => '大愛旅行社有限公司',
        'ai_tone' => '親切',
        'travel_specialties' => '綜合旅遊',
        'price_catalog_json' => '{}',
      ),
      'features' => 
      array (
        'tour_prompt' => false,
        'hybrid_search' => false,
        'fixed_formatter' => false,
        'bats_runtime' => true,
        'aiu_authoritative' => true,
        'grounding_authoritative' => true,
      ),
      'gemini_policy' => 
      array (
        'tone' => 'travel_assistant',
        'allow_fixed_formatter' => false,
        'allow_gemini_rewrite' => false,
      ),
      'source_policy' => 
      array (
        'host_b_enabled' => false,
        'search_client' => 'gateway_php',
      ),
    ),
  ),
);
