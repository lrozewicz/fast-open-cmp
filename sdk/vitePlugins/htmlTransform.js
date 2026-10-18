/**
 * Plugin that transforms index.html based on build mode
 */
export function htmlTransform() {
  return {
    name: 'html-transform',

    transformIndexHtml: {
      order: 'pre',
      handler(html, ctx) {
        const isDev = ctx.server !== undefined;

        if (isDev) {
          // Development mode - use TypeScript module
          return html.replace(
            '<script src="./src/index.js?v=__RANDOM_CACHE_BUSTER__"></script>',
            '<script type="module" src="src/index.ts"></script>'
          );
        } else {
          // Production mode - use built JS file with cache buster
          const randomCacheBuster = Math.random().toString(36).substring(7);
          return html.replace(
            '__RANDOM_CACHE_BUSTER__',
            randomCacheBuster
          );
        }
      }
    }
  };
}