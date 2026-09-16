const expoConfig = require('eslint-config-expo/flat');

/**
 * NativeWind's interop wrappers rebuild the `style` prop from plain
 * objects/arrays; a `style={({ pressed }) => …}` callback matches neither and
 * is silently dropped on-device (it still renders correctly under jest, which
 * skips the interop registry, so the break only shows up on a real phone).
 * Track press state with useState + onPressIn/onPressOut instead.
 */
const noStyleCallback = {
  selector:
    'JSXAttribute[name.name="style"] > JSXExpressionContainer > :matches(ArrowFunctionExpression, FunctionExpression)',
  message:
    'A style callback is dropped by NativeWind on device. Use a plain style value and track press state with onPressIn/onPressOut.',
};

module.exports = [
  ...expoConfig,
  {
    ignores: ['dist/*', '.expo/*', 'node_modules/*'],
  },
  {
    rules: {
      'no-restricted-syntax': ['error', noStyleCallback],
    },
  },
];
