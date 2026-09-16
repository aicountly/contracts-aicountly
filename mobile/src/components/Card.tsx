import { View, type ViewProps } from 'react-native';
import { colors, cardShadow } from '../theme/colors';

export function Card({ children, style, ...rest }: ViewProps) {
  return (
    <View
      style={[
        { backgroundColor: '#FFFFFF', borderRadius: 16, padding: 16, borderWidth: 1, borderColor: colors.borderSoft, ...cardShadow },
        style,
      ]}
      {...rest}
    >
      {children}
    </View>
  );
}
