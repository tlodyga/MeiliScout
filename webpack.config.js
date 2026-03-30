const path = require('path');
const fs = require('fs');
const defaultConfig = require('@wordpress/scripts/config/webpack.config');
const MiniCssExtractPlugin = require('mini-css-extract-plugin');


/**
 * Returns all PHP/JS files under dir as paths relative to the CSS file
 * (resources/assets/css/app.css), so @source directives resolve correctly.
 */
function getSourceFiles(dir, extensions = ['.php', '.js']) {
    const files = [];
    const ignoreDirs = new Set(['node_modules', 'vendor', 'build', '.git']);
    const cssDir = path.resolve(__dirname, 'resources/assets/css');

    function walk(currentDir) {
        let entries;
        try {
            entries = fs.readdirSync(currentDir, { withFileTypes: true });
        } catch {
            return;
        }
        for (const entry of entries) {
            if (ignoreDirs.has(entry.name)) continue;
            const fullPath = path.join(currentDir, entry.name);
            if (entry.isDirectory()) {
                walk(fullPath);
            } else if (extensions.some(ext => entry.name.endsWith(ext))) {
                const rel = path.relative(cssDir, fullPath).replace(/\\/g, '/');
                files.push(rel.startsWith('.') ? rel : './' + rel);
            }
        }
    }

    walk(dir);
    return files;
}

const sourceFiles = getSourceFiles(path.resolve(__dirname, 'resources'));

const injectSourcesPlugin = () => ({
    postcssPlugin: 'inject-tailwind-sources',
    Once(root) {
        const postcss = require('postcss');
        for (const file of sourceFiles) {
            root.prepend(postcss.atRule({ name: 'source', params: `"${file}"` }));
        }
    },
});
injectSourcesPlugin.postcss = true;

module.exports = {
    ...defaultConfig,
    entry: {
        'main': path.resolve(__dirname, 'resources/assets/main.js'),
    },
    output: {
        path: path.resolve(__dirname, 'build'),
        filename: '[name].js',
    },
    module: {
        ...defaultConfig.module,
        rules: [
            ...defaultConfig.module.rules.filter(rule => !rule.test || !rule.test.test('.css')),
            {
                test: /\.css$/,
                use: [
                    MiniCssExtractPlugin.loader,
                    'css-loader',
                    {
                        loader: 'postcss-loader',
                        options: {
                            postcssOptions: {
                                config: false,
                                plugins: [
                                    injectSourcesPlugin,
                                    require('@tailwindcss/postcss')({ base: __dirname }),
                                ],
                            },
                        },
                    },
                ],
            },
        ],
    },
    plugins: [
        ...defaultConfig.plugins,
        new MiniCssExtractPlugin({
            filename: '[name].css',
        }),
    ],
};
