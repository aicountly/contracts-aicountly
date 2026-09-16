module.exports = function (api) {
  api.cache(true);
  return {
    presets: [
      ['babel-preset-expo', { jsxImportSource: 'nativewind' }],
      'nativewind/babel',
    ],
    plugins: [
      // Required for release builds — reanimated/plugin delegates to worklets/plugin.
      'react-native-reanimated/plugin',
    ],
  };
};
