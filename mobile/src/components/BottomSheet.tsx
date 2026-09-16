import type { ReactNode } from 'react';
import { Modal, Pressable, View } from 'react-native';

interface BottomSheetProps {
  visible: boolean;
  onClose: () => void;
  children: ReactNode;
  maxHeightPct?: number;
}

/**
 * Backdrop Pressable behind a content Pressable-with-no-op-onPress — the
 * standard RN pattern for a tap-outside-to-close sheet. Without the inner
 * no-op Pressable, an unhandled tap inside the sheet's own padding bubbles up
 * to the backdrop's responder and closes it.
 */
export function BottomSheet({ visible, onClose, children, maxHeightPct = 70 }: BottomSheetProps) {
  return (
    <Modal visible={visible} animationType="slide" transparent onRequestClose={onClose}>
      <Pressable style={{ flex: 1, backgroundColor: 'rgba(0,0,0,0.35)', justifyContent: 'flex-end' }} onPress={onClose}>
        <Pressable
          onPress={() => {}}
          style={{ backgroundColor: '#FFFFFF', borderTopLeftRadius: 20, borderTopRightRadius: 20, maxHeight: `${maxHeightPct}%` }}
        >
          {children}
        </Pressable>
      </Pressable>
    </Modal>
  );
}
