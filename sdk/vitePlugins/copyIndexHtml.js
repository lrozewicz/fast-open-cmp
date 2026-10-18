// vitePlugins/copyIndexHtml.js
import fs from 'fs';
import path from 'path';

/**
 * Plugin that copies index.html file to the output directory
 */
export function copyIndexHtml() {
  return {
    name: 'copy-index-html',
    
    // Called at the end of the bundle process
    writeBundle: {
      handler({ dir }) {
        const sourceFile = path.resolve('./index.html');
        const destFile = path.resolve(path.join(dir, 'index.html'));
        
        // Copy index.html to the output directory
        if (fs.existsSync(sourceFile)) {
          // Create directories if they don't exist
          const destDir = path.dirname(destFile);
          if (!fs.existsSync(destDir)) {
            fs.mkdirSync(destDir, { recursive: true });
          }
          
          // Copy the file
          fs.copyFileSync(sourceFile, destFile);
          console.log(`Copied index.html to ${destFile}`);
        } else {
          console.error(`Source file ${sourceFile} does not exist`);
        }
      }
    }
  };
}