import { Ionicons } from "@expo/vector-icons";
import { useRouter } from "expo-router";
import { useCallback, useEffect, useState } from "react";
import {
  ActivityIndicator,
  Alert,
  FlatList,
  Modal,
  Pressable,
  StyleSheet,
  Text,
  TextInput,
  TouchableOpacity,
  View,
} from "react-native";
import { SafeAreaView } from "react-native-safe-area-context";
import PrimaryButton from "../../components/ui/PrimaryButton";
import { COLORS, RADIUS, SPACING } from "../../constants/colors";
import { useAuth } from "../../contexts/AuthContext";
import {
  fetchSupportMessages,
  replyToSupportMessage,
} from "../../services/api/support";

const STATUS_STYLES = {
  open: { bg: COLORS.warningBg, fg: COLORS.warning, label: "Open" },
  replied: { bg: COLORS.successBg, fg: COLORS.success, label: "Replied" },
};

export default function AdminSupportScreen() {
  const router = useRouter();
  const { token } = useAuth();

  const [messages, setMessages] = useState([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);

  const [selected, setSelected] = useState(null);
  const [replyText, setReplyText] = useState("");
  const [replying, setReplying] = useState(false);

  const loadMessages = useCallback(
    async (isRefresh = false) => {
      if (!token) return;
      if (isRefresh) setRefreshing(true);
      try {
        const res = await fetchSupportMessages(token);
        if (res.success && Array.isArray(res.data)) {
          setMessages(
            res.data.map((message) => ({
              ...message,
              message_id: message.message_id ?? message.id,
            })),
          );
        }
      } catch {
        // Soft fail
      } finally {
        setLoading(false);
        setRefreshing(false);
      }
    },
    [token],
  );

  useEffect(() => {
    loadMessages();
  }, [loadMessages]);

  const handleReply = async () => {
    if (!replyText.trim()) {
      Alert.alert("Missing", "Please type a reply.");
      return;
    }
    if (!selected?.message_id) {
      Alert.alert("Error", "This support message has no valid message ID.");
      return;
    }
    setReplying(true);
    try {
      const res = await replyToSupportMessage(
        token,
        selected.message_id,
        replyText.trim(),
      );
      setReplying(false);
      if (res.success) {
        setSelected(null);
        setReplyText("");
        loadMessages();
      } else {
        Alert.alert("Error", res.message || "Could not send reply.");
      }
    } catch {
      setReplying(false);
      Alert.alert("Connection Error", "Could not reach the server.");
    }
  };

  const renderItem = ({ item }) => {
    const s = STATUS_STYLES[item.status] ?? STATUS_STYLES.open;
    return (
      <TouchableOpacity
        style={styles.card}
        onPress={() => {
          setSelected(item);
          setReplyText(item.admin_reply || "");
        }}
        accessibilityRole="button"
      >
        <View style={styles.cardHeader}>
          <View style={{ flex: 1 }}>
            <Text style={styles.cardSender} numberOfLines={1}>
              {item.full_name || "Unknown"}{" "}
              <Text style={styles.cardRole}>({item.role})</Text>
            </Text>
            <Text style={styles.cardSubject} numberOfLines={1}>
              {item.subject}
            </Text>
          </View>
          <View style={[styles.badge, { backgroundColor: s.bg }]}>
            <Text style={[styles.badgeText, { color: s.fg }]}>{s.label}</Text>
          </View>
        </View>
        <Text style={styles.cardPreview} numberOfLines={2}>
          {item.message}
        </Text>
        <Text style={styles.cardDate}>
          {new Date(item.created_at).toLocaleString()}
        </Text>
      </TouchableOpacity>
    );
  };

  return (
    <SafeAreaView style={styles.safe}>
      <View style={styles.header}>
        <TouchableOpacity onPress={() => router.back()} style={styles.backBtn}>
          <Ionicons name="arrow-back" size={22} color={COLORS.primary} />
        </TouchableOpacity>
        <Text style={styles.title}>Support Messages</Text>
        <View style={{ width: 30 }} />
      </View>

      {loading ? (
        <ActivityIndicator
          color={COLORS.primary}
          size="large"
          style={{ marginTop: 40 }}
        />
      ) : (
        <FlatList
          data={messages}
          keyExtractor={(item) => String(item.message_id)}
          renderItem={renderItem}
          contentContainerStyle={styles.list}
          refreshing={refreshing}
          onRefresh={() => loadMessages(true)}
          ListEmptyComponent={
            <Text style={styles.emptyText}>No support messages yet.</Text>
          }
        />
      )}

      {/* Reply Modal */}
      <Modal
        visible={!!selected}
        animationType="slide"
        transparent
        onRequestClose={() => setSelected(null)}
      >
        <Pressable style={styles.overlay} onPress={() => setSelected(null)}>
          <Pressable style={styles.modal} onPress={(e) => e.stopPropagation()}>
            <Text style={styles.modalTitle}>{selected?.subject}</Text>
            <Text style={styles.modalSender}>
              From: {selected?.full_name} ({selected?.email})
            </Text>
            <View style={styles.msgBox}>
              <Text style={styles.msgText}>{selected?.message}</Text>
            </View>

            <Text style={styles.replyLabel}>Admin Reply</Text>
            <TextInput
              style={styles.replyInput}
              value={replyText}
              onChangeText={setReplyText}
              placeholder="Type your reply..."
              multiline
              numberOfLines={4}
              placeholderTextColor={COLORS.textMuted}
            />

            <View style={styles.modalActions}>
              <TouchableOpacity
                style={styles.cancelBtn}
                onPress={() => setSelected(null)}
              >
                <Text style={styles.cancelText}>Cancel</Text>
              </TouchableOpacity>
              <View style={{ flex: 1 }}>
                <PrimaryButton
                  title="Send Reply"
                  onPress={handleReply}
                  loading={replying}
                />
              </View>
            </View>
          </Pressable>
        </Pressable>
      </Modal>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: COLORS.background },
  header: {
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "space-between",
    paddingHorizontal: SPACING.md,
    paddingVertical: SPACING.sm,
    borderBottomWidth: 1,
    borderBottomColor: COLORS.border,
    backgroundColor: COLORS.surface,
  },
  backBtn: { padding: 4 },
  title: { fontSize: 17, fontWeight: "700", color: COLORS.textPrimary },
  list: { padding: SPACING.md, paddingBottom: SPACING.xl },
  card: {
    backgroundColor: COLORS.surface,
    borderRadius: RADIUS.md,
    padding: SPACING.md,
    marginBottom: SPACING.sm,
    borderWidth: 1,
    borderColor: COLORS.border,
  },
  cardHeader: {
    flexDirection: "row",
    justifyContent: "space-between",
    alignItems: "flex-start",
    marginBottom: 4,
  },
  cardSender: { fontSize: 13, fontWeight: "700", color: COLORS.textPrimary },
  cardRole: { fontWeight: "400", color: COLORS.textSecondary },
  cardSubject: { fontSize: 12, color: COLORS.textSecondary, marginTop: 2 },
  badge: {
    paddingHorizontal: 8,
    paddingVertical: 2,
    borderRadius: 99,
    marginLeft: 8,
  },
  badgeText: { fontSize: 11, fontWeight: "600" },
  cardPreview: { fontSize: 13, color: COLORS.textMuted, marginBottom: 6 },
  cardDate: { fontSize: 11, color: COLORS.textMuted },
  emptyText: { textAlign: "center", color: COLORS.textMuted, marginTop: 40 },
  overlay: {
    flex: 1,
    backgroundColor: "rgba(0,0,0,0.5)",
    justifyContent: "flex-end",
  },
  modal: {
    backgroundColor: COLORS.surface,
    borderTopLeftRadius: RADIUS.xl,
    borderTopRightRadius: RADIUS.xl,
    padding: SPACING.lg,
    paddingBottom: SPACING.xl,
  },
  modalTitle: {
    fontSize: 17,
    fontWeight: "700",
    color: COLORS.textPrimary,
    marginBottom: 4,
  },
  modalSender: {
    fontSize: 13,
    color: COLORS.textSecondary,
    marginBottom: SPACING.sm,
  },
  msgBox: {
    backgroundColor: COLORS.surfaceAlt,
    borderRadius: RADIUS.sm,
    padding: SPACING.sm,
    marginBottom: SPACING.md,
  },
  msgText: { fontSize: 14, color: COLORS.textPrimary },
  replyLabel: {
    fontSize: 12,
    fontWeight: "700",
    color: COLORS.textSecondary,
    textTransform: "uppercase",
    marginBottom: 6,
  },
  replyInput: {
    borderWidth: 1,
    borderColor: COLORS.border,
    borderRadius: RADIUS.md,
    padding: SPACING.sm,
    color: COLORS.textPrimary,
    fontSize: 14,
    minHeight: 90,
    textAlignVertical: "top",
    marginBottom: SPACING.md,
  },
  modalActions: { flexDirection: "row", alignItems: "center", gap: SPACING.sm },
  cancelBtn: {
    paddingVertical: 12,
    paddingHorizontal: SPACING.md,
    borderWidth: 1,
    borderColor: COLORS.border,
    borderRadius: RADIUS.md,
  },
  cancelText: { color: COLORS.textSecondary, fontWeight: "600" },
});
