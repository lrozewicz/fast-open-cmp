// API Configuration
// These globals are replaced at build time by Vite's `define` option:
// - Production build: placeholders for WordPress PHP replacement ({API_BASE_URL}, {GVL_URL})
// - Dev build: localhost URLs for local development
declare const __CMP_API_BASE_URL__: string;
declare const __CMP_GVL_URL__: string;

export const API_BASE_URL: string = __CMP_API_BASE_URL__;
export const GVL_BASE_URL: string = __CMP_GVL_URL__;
