import { Ionicons } from "@expo/vector-icons";
import { useCallback, useEffect, useState } from "react";
import {
  ActivityIndicator,
  Alert,
  FlatList,
  KeyboardAvoidingView,
  Platform,
  StyleSheet,
  Text,
  TouchableOpacity,
  View,
} from "react-native";
import { SafeAreaView } from "react-native-safe-area-context";
import { useRouter } from "expo-router";
import CustomInput from "../../components/ui/CustomInput";
import PrimaryButton from "../../components/ui/PrimaryButton";
import { COLORS, RADIUS, SPACING } from "../../constants/colors";
import { useAuth } from "../../contexts/AuthContext";
import {
  fetchSupportMessages,
  sendSupportMessage,
} from "../../services/api/support";

const STATUS_STYLES = {
  open: { bg: COLORS.warningBg, fg: COLORS.warning, label: "Open" },
  replied: { bg: COLORS.successBg, fg: COLORS.success, label: "Replied" },
};

export default function SupportScreen() {
  const router = useRouter();
  const { token } = useAuth();

  const [subject, setSubject] = useState("");
  const [message, setMessage] = useState("");
  const [sending, setSending] = useState(false);

  const [messages, setMessages] = useState([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);

  const loadMessages = useCallback(
    async (isRefresh = false) => {
      if (!token) return;
      if (isRefresh) setRefreshing(true);
      try {
        const res = await fetchSupportMessages(token);
        if (res.success && Array.isArray(res.data)) {
          setMessages(res.data);
        }
      } catch {
        // Soft fail
      } finally {
        setLoading(false);
        setRefreshing(false);
      }
    },
    [token]
  );

  useEffect(() => {
    loadMessages();
  }, [loadMessages]);

  const handleSend = async () => {
    if (!subject.trim() || !message.trim()) {
      Alert.alert("Missing Fields", "Please enter a subject and message.");
      return;
    }
    setSending(true);
    try {
      const res = await sendSupportMessage(token, subject.trim(), message.trim());
      setSending(false);
      if (res.success) {
        setSubject("");
        setMessage("");
        Alert.alert("Sent!", "Your message has been sent to the admin.");
        loadMessages();
      } else {
        Alert.alert("Error", res.message || "Could not send message.");
      }
    } catch {
      setSending(false);
      Alert.alert("Connection Error", "Could not reach the server.");
    }
  };

  const renderItem = ({ item }) => {
    const s = STATUS_STYLES[item.status] ?? STATUS_STYLES.open;
    return (
      <View style={styles.card}>
        <View style={styles.cardHeader}>
          <Text style={styles.cardSubject} numberOfLines={1}>
            {item.subject}
          </Text>
          <View style={[styles.badge, { backgroundColor: s.bg }]}>
            <Text style={[styles.badgeText, { color: s.fg }]}>{s.label}</Text>
          </View>
        </View>
        <Text style={styles.cardMessage}>{item.message}</Text>
        {item.admin_reply ? (
          <View style={styles.replyBox}>
            <Ionicons name="chatbubble-ellipses-outline" size={14} color={COLORS.primary} />
            <Text style={styles.replyText}>{item.admin_reply}</Text>
          </View>
        ) : null}
        <Text style={styles.cardDate}>
          {new Date(item.created_at).toLocaleDateString()}
        </Text>
      </View>
    );
  };

  return (
    <SafeAreaView style={styles.safe}>
      <KeyboardAvoidingView
        style={styles.flex}
        behavior={Platform.OS === "ios" ? "padding" : "height"}
      >
        <View style={styles.header}>
          <TouchableOpacity onPress={() => router.back()} style={styles.backBtn}>
            <Ionicons name="arrow-back" size={22} color={COLORS.primary} />
          </TouchableOpacity>
          <Text style={styles.title}>Contact Support</Text>
          <View style={{ width: 30 }} />
        </View>

        <FlatList
          data={messages}
          keyExtractor={(item) => String(item.message_id ?? item.id)}
          renderItem={renderItem}
          refreshing={refreshing}
          onRefresh={() => loadMessages(true)}
          contentContainerStyle={styles.list}
          keyboardShouldPersistTaps="handled"
          ListHeaderComponent={
            <View style={styles.formCard}>
              <Text style={styles.sectionTitle}>Send a Message</Text>
              <CustomInput
                label="Subject"
                value={subject}
                onChangeText={setSubject}
                placeholder="e.g. Booking issue"
              />
              <CustomInput
                label="Message"
                value={message}
                onChangeText={setMessage}
                placeholder="Describe your issue..."
                multiline
                numberOfLines={4}
              />
              <View style={styles.sendBtn}>
                <PrimaryButton
                  title="Send Message"
                  onPress={handleSend}
                  loading={sending}
                />
              </View>
            </View>
          }
          ListHeaderComponentStyle={{ marginBottom: SPACING.sm }}
          ListEmptyComponent={
            loading ? (
              <ActivityIndicator
                color={COLORS.primary}
                style={{ marginTop: SPACING.lg }}
              />
            ) : (
              <Text style={styles.emptyText}>No messages yet.</Text>
            )
          }
          ListFooterComponent={
            messages.length > 0 ? (
              <Text style={styles.historyLabel}>
                Previous Messages ({messages.length})
              </Text>
            ) : null
          }
        />
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: COLORS.background },
  flex: { flex: 1 },
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
  formCard: {
    backgroundColor: COLORS.surface,
    borderRadius: RADIUS.lg,
    padding: SPACING.md,
    borderWidth: 1,
    borderColor: COLORS.border,
  },
  sectionTitle: {
    fontSize: 14,
    fontWeight: "700",
    color: COLORS.textSecondary,
    textTransform: "uppercase",
    letterSpacing: 0.5,
    marginBottom: SPACING.sm,
  },
  sendBtn: { marginTop: SPACING.xs },
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
    alignItems: "center",
    marginBottom: 4,
  },
  cardSubject: {
    fontSize: 14,
    fontWeight: "700",
    color: COLORS.textPrimary,
    flex: 1,
    marginRight: 8,
  },
  badge: {
    paddingHorizontal: 8,
    paddingVertical: 2,
    borderRadius: 99,
  },
  badgeText: { fontSize: 11, fontWeight: "600" },
  cardMessage: { fontSize: 13, color: COLORS.textSecondary, marginBottom: 6 },
  replyBox: {
    flexDirection: "row",
    gap: 6,
    backgroundColor: COLORS.surfaceAlt,
    borderRadius: RADIUS.sm,
    padding: SPACING.sm,
    marginBottom: 6,
    alignItems: "flex-start",
  },
  replyText: { fontSize: 13, color: COLORS.primary, flex: 1 },
  cardDate: { fontSize: 11, color: COLORS.textMuted },
  emptyText: {
    textAlign: "center",
    color: COLORS.textMuted,
    marginTop: SPACING.md,
  },
  historyLabel: {
    fontSize: 13,
    fontWeight: "700",
    color: COLORS.textSecondary,
    textTransform: "uppercase",
    letterSpacing: 0.5,
    marginBottom: SPACING.sm,
    marginTop: SPACING.sm,
  },
});

