import { CMPConfig, VendorListData } from '../models/types';
import { API_BASE_URL } from '../data/cmpConfig';
import { domainQuery } from './domainRef';

/**
 * Klasa odpowiedzialna za komunikację z API
 */
export class ApiService {
  private baseUrl: string;
  private vendorListCache: Map<string, VendorListData> = new Map();
  // 'test' serves the domain's TEST template (/cmp-config?env=test)
  private environment: 'prod' | 'test' = 'prod';

  constructor(baseUrl?: string) {
    this.baseUrl = baseUrl || API_BASE_URL;
  }

  setEnvironment(env: 'prod' | 'test'): void {
    this.environment = env === 'test' ? 'test' : 'prod';
  }

  getEnvironment(): 'prod' | 'test' {
    return this.environment;
  }

  getBaseUrl(): string {
    return this.baseUrl;
  }

  /**
   * Pobiera konfigurację CMP z API
   * @param domain - Domena, dla której pobierana jest konfiguracja
   * @param domainId - public key of the domain, or its numeric id (older snippets)
   * @returns Konfiguracja CMP
   */
  async fetchCMPConfig(domain: string, domainId?: string): Promise<CMPConfig> {
    // First fetch the basic config, then complete it with the vendor list
    const config = await this.fetchBasicConfig(domain, domainId);
    return this.completeConfig(config);
  }

  /**
   * Completes a basic config (from /cmp-config or the admin preview) with the
   * vendor list: purposes, features, stacks and the IAB vendors of the active list.
   */
  async completeConfig(config: CMPConfig): Promise<CMPConfig> {
    try {
      // Fetch the vendor list to get purposes, features, and IAB vendors
      const vendorListData = await this.fetchVendorList(config.gvlUrl || '', config.cacheVersion);

      // Merge the vendor list data with the config
      const completeConfig: CMPConfig = {
        ...config,
        language: config.language || 'en',
        purposes: vendorListData.purposes,
        specialFeatures: vendorListData.specialFeatures,
        specialPurposes: vendorListData.specialPurposes,
        features: vendorListData.features,
        stacks: vendorListData.stacks,
        dataCategories: vendorListData.dataCategories,
        vendors: {
          iab: vendorListData.vendors,
          custom: config.vendors?.custom || { google: {}, other: {} },
        },
      };

      // Restrict IAB vendors to those enabled in the active list. null/absent =
      // no restriction (show all). This makes the consent box reflect the vendor
      // list exactly (e.g. one checked vendor → one vendor shown), instead of
      // always showing the full global GVL.
      if (Array.isArray(config.iabVendorIds)) {
        const allowed = new Set(config.iabVendorIds.map(String));
        const filteredIab: typeof completeConfig.vendors.iab = {};
        for (const id in completeConfig.vendors.iab) {
          if (allowed.has(String(id))) {
            filteredIab[id] = completeConfig.vendors.iab[id];
          }
        }
        completeConfig.vendors.iab = filteredIab;
      }

      // Apply vendor counts from metadata if available
      if (config.purposesMetadata && config.purposesMetadata.vendorCounts) {
        for (const purposeId in config.purposesMetadata.vendorCounts) {
          if (completeConfig.purposes[purposeId]) {
            completeConfig.purposes[purposeId].vendorsCount =
              (config.purposesMetadata.vendorCounts[purposeId].purposes || 0) +
              (config.purposesMetadata.vendorCounts[purposeId].legIntPurposes || 0);
            completeConfig.purposes[purposeId].legIntVendorsCount =
              config.purposesMetadata.vendorCounts[purposeId].legIntPurposes;
          }
        }
      }

      if (config.specialFeaturesMetadata && config.specialFeaturesMetadata.vendorCounts) {
        for (const featureId in config.specialFeaturesMetadata.vendorCounts) {
          if (completeConfig.specialFeatures[featureId]) {
            completeConfig.specialFeatures[featureId].vendorsCount =
              config.specialFeaturesMetadata.vendorCounts[featureId];
          }
        }
      }

      if (config.specialPurposesMetadata && config.specialPurposesMetadata.vendorCounts) {
        for (const spId in config.specialPurposesMetadata.vendorCounts) {
          if (completeConfig.specialPurposes[spId]) {
            completeConfig.specialPurposes[spId].vendorsCount =
              config.specialPurposesMetadata.vendorCounts[spId];
          }
        }
      }

      if (config.featuresMetadata && config.featuresMetadata.vendorCounts) {
        for (const fId in config.featuresMetadata.vendorCounts) {
          if (completeConfig.features[fId]) {
            completeConfig.features[fId].vendorsCount = config.featuresMetadata.vendorCounts[fId];
          }
        }
      }

      return completeConfig;
    } catch (error) {
      console.error('Error fetching CMP config:', error);
      throw error;
    }
  }

  /**
   * Fetches the basic configuration from the API
   * @param domain - Domain to fetch configuration for
   * @param domainId - public key of the domain, or its numeric id (older snippets)
   * @returns Basic CMP configuration
   */
  private async fetchBasicConfig(domain: string, domainId?: string): Promise<CMPConfig> {
    const url = new URL(`${this.baseUrl}/cmp-config`);

    // Add parameters to URL
    url.searchParams.append('domain', domain);
    for (const [name, value] of Object.entries(domainQuery(domainId))) {
      url.searchParams.append(name, value);
    }
    if (this.environment === 'test') {
      url.searchParams.append('env', 'test');
    }

    const response = await fetch(url.toString());

    if (!response.ok) {
      throw new Error(`API error: ${response.status} ${response.statusText}`);
    }

    return await response.json();
  }

  /**
   * Fetches the vendor list from the API or external source
   * @param gvlUrl - URL to fetch the vendor list from
   * @returns Vendor list data
   */
  private async fetchVendorList(gvlUrl: string, cacheVersion?: number): Promise<VendorListData> {
    // Check if we have cached this vendor list in memory
    if (this.vendorListCache.has(gvlUrl)) {
      console.log('Using cached vendor list data for:', gvlUrl);
      const cached = this.vendorListCache.get(gvlUrl);
      if (cached) return cached;
    }

    // Check if we have cached this vendor list in localStorage
    try {
      const cachedData = localStorage.getItem(`vendor-list-${gvlUrl}`);
      if (cachedData) {
        const parsed = JSON.parse(cachedData);
        const now = Date.now();

        // Invalidate if server cache version changed
        const cacheVersionValid = !cacheVersion || parsed.cacheVersion === cacheVersion;

        // Check if cache is still valid (24 hours) and cache version matches
        if (cacheVersionValid && parsed.timestamp && now - parsed.timestamp < 24 * 60 * 60 * 1000) {
          console.log('Using localStorage cached vendor list data for:', gvlUrl);
          this.vendorListCache.set(gvlUrl, parsed.data);
          window.__vendorListData = parsed.data;
          return parsed.data;
        }

        if (!cacheVersionValid) {
          console.log('Server cache version changed, invalidating localStorage vendor list cache');
        }
      }
    } catch (error) {
      console.warn('Error reading vendor list from localStorage:', error);
    }

    console.log('Fetching vendor list from:', gvlUrl);
    const response = await fetch(gvlUrl);

    if (!response.ok) {
      throw new Error(`API error: ${response.status} ${response.statusText}`);
    }

    const data = await response.json();

    // Cache the result in memory
    this.vendorListCache.set(gvlUrl, data);

    // Cache the result in localStorage with timestamp and cache version
    try {
      localStorage.setItem(
        `vendor-list-${gvlUrl}`,
        JSON.stringify({
          data: data,
          timestamp: Date.now(),
          cacheVersion: cacheVersion || 0,
        }),
      );
    } catch (error) {
      console.warn('Error saving vendor list to localStorage:', error);
    }

    // Attach the fetched data to window so other components can use it
    window.__vendorListData = data;

    return data;
  }

  /**
   * Gets cached vendor list data if available
   * @returns Cached vendor list data or null
   */
  getCachedVendorList(): VendorListData | null {
    const cachedList =
      this.vendorListCache.size > 0 ? this.vendorListCache.values().next().value : null;

    return cachedList || window.__vendorListData || null;
  }
}

export default new ApiService();
