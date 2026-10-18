-- E2E seed: three domains/templates, one per CMP mode.
-- Idempotent — safe to re-run. Uses fixed high ids (901-903) to avoid colliding
-- with real auto-increment rows. Clones the config of template 1, flipping only
-- `mode` and `googleConsentMode.enabled`.
--
-- Apply with: docker compose exec -T db mariadb -uroot -proot wordpress < e2e/seed.sql
--
-- Domain ids consumed by the parametrized test page (docs/examples/cmp-e2e.html):
--   901 = tcf_gcm   902 = gcm_only   903 = basic
--   904 = tcf_gcm with the "custom" compliance profile (publisher-defined layer 1)
--
-- Layer-1 / appearance settings of template 1 are removed from 901-903 so they run
-- with the defaults (certified profile), independent of how template 1 is edited.

DELETE FROM wp_cmp_domains   WHERE id IN (901,902,903,904);
DELETE FROM wp_cmp_templates WHERE id IN (901,902,903,904);

-- 901 tcf_gcm — GCM enabled so an `update` signal fires on accept; vendor list 1.
INSERT INTO wp_cmp_templates (id,name,language,vendor_list_id,config)
SELECT 901,'e2e tcf_gcm','pl',1,
  JSON_SET(JSON_REMOVE(config,'$.firstLayer','$.appearance','$.complianceProfile'),'$.mode','tcf_gcm','$.googleConsentMode.enabled',1)
FROM wp_cmp_templates WHERE id=1;

-- 902 gcm_only — no TCF / no TC String; GCM enabled; vendor list 1.
INSERT INTO wp_cmp_templates (id,name,language,vendor_list_id,config)
SELECT 902,'e2e gcm_only','pl',1,
  JSON_SET(JSON_REMOVE(config,'$.firstLayer','$.appearance','$.complianceProfile'),'$.mode','gcm_only','$.googleConsentMode.enabled',1)
FROM wp_cmp_templates WHERE id=1;

-- 903 basic — simplest box, no vendor list (layer 3), GCM disabled.
INSERT INTO wp_cmp_templates (id,name,language,vendor_list_id,config)
SELECT 903,'e2e basic','pl',NULL,
  JSON_SET(JSON_REMOVE(config,'$.firstLayer','$.appearance','$.complianceProfile'),'$.mode','basic','$.googleConsentMode.enabled',0)
FROM wp_cmp_templates WHERE id=1;

-- 904 tcf_gcm, custom profile: settings as a text link + accept only, an "×" that
-- rejects, and the floating "privacy settings" button.
INSERT INTO wp_cmp_templates (id,name,language,vendor_list_id,config)
SELECT 904,'e2e custom profile','pl',1,
  JSON_SET(JSON_REMOVE(config,'$.firstLayer','$.appearance','$.complianceProfile'),
    '$.mode','tcf_gcm','$.googleConsentMode.enabled',1,
    '$.complianceProfile','custom',
    '$.firstLayer',JSON_OBJECT(
      'buttons',JSON_ARRAY(
        JSON_OBJECT('action','settings','variant','link','label','Więcej opcji'),
        JSON_OBJECT('action','accept-all','variant','primary','label','')),
      'closeButton','reject-all','showTcfSummary',false),
    '$.appearance',JSON_OBJECT(
      'layout','bar','colors',JSON_OBJECT('primary','#0b5ed7'),
      'reopenButton',JSON_OBJECT('enabled',true,'position','bottom-right')))
FROM wp_cmp_templates WHERE id=1;

-- Fixed public keys (snippets use ?key=…; domain_id is the legacy form).
INSERT INTO wp_cmp_domains (id,company_id,domain,prod_template_id,test_template_id,public_key)
VALUES (901,1,'e2e-tcf.local',901,901,'e2etcfkey00000000901'),
       (902,1,'e2e-gcm.local',902,902,'e2egcmkey00000000902'),
       (903,1,'e2e-basic.local',903,903,'e2ebasickey000000903'),
       (904,1,'e2e-custom.local',904,904,'e2ecustomkey00000904');

-- Invalidate the cmp-config transient cache so the new templates are served.
DELETE FROM wp_options
  WHERE option_name LIKE '\_transient\_focmp\_%'
     OR option_name LIKE '\_transient\_timeout\_focmp\_%';
UPDATE wp_options SET option_value = CAST(option_value AS UNSIGNED)+1
  WHERE option_name='focmp_cache_version';
