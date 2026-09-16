import { View, type ViewProps } from 'react-native';
import { colors } from '../theme/colors';

export function Skeleton({ style, ...rest }: ViewProps) {
  return <View style={[{ backgroundColor: colors.track, borderRadius: 8, overflow: 'hidden' }, style]} {...rest} />;
}
