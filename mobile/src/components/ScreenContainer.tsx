import { View, type ViewProps } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

interface ScreenContainerProps extends ViewProps {
  /** Skip bottom inset padding for a screen that sits above the tab bar (which already reserves that space). */
  bottomInset?: boolean;
}

/**
 * Base screen wrapper: white background, safe-area padding applied via
 * useSafeAreaInsets rather than <SafeAreaView> — the latter needs NativeWind's
 * cssInterop to accept `className`/`style` reliably, and app/(app)/_layout.tsx
 * already establishes the insets-hook convention for this app.
 */
export function ScreenContainer({ children, style, bottomInset = true, ...rest }: ScreenContainerProps) {
  const insets = useSafeAreaInsets();
  return (
    <View style={[{ flex: 1, backgroundColor: '#FFFFFF', paddingTop: insets.top, paddingBottom: bottomInset ? insets.bottom : 0 }, style]} {...rest}>
      {children}
    </View>
  );
}
