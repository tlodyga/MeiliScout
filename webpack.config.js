const path = require('path');
const defaultConfig = require('@wordpress/scripts/config/webpack.config');
const MiniCssExtractPlugin = require('mini-css-extract-plugin');

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
                                plugins: [
                                    require('@tailwindcss/postcss'),
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
