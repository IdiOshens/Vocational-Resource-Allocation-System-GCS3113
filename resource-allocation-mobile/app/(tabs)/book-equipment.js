import { Ionicons } from "@expo/vector-icons";
import { useLocalSearchParams, useRouter } from "expo-router";
import { useState } from "react";
import {
    Alert,
    Image,
    KeyboardAvoidingView,
    Platform,
    ScrollView,
    StyleSheet,
    Text,
    TextInput,
    TouchableOpacity,
    View,
} from "react-native";
import { SafeAreaView } from "react-native-safe-area-context";

import CombinedDateTimePicker from "../../components/ui/CombinedDateTimePicker";
import { useAuth } from "../../contexts/AuthContext";
import { createBooking } from "../../services/api/auth";
import { COLORS } from "../../constants/colors";

export default function BookEquipmentScreen() {
  const router = useRouter();
  const params = useLocalSearchParams();
  const { token } = useAuth();

  const equipmentId = params.equipmentId ? Number(params.equipmentId) : 0;
  const equipmentLabel = params.equipmentLabel ?? "Equipment";
  const imageUrl = Array.isArray(params.imageUrl) ? params.imageUrl[0] : params.imageUrl;

  const [startDate, setStartDate] = useState("");
  const [startTime, setStartTime] = useState("");
  const [endDate, setEndDate] = useState("");
  const [endTime, setEndTime] = useState("");
  const [purpose, setPurpose] = useState("");
  const [priority, setPriority] = useState("medium");
  const [isLoading, setIsLoading] = useState(false);
  const [errors, setErrors] = useState({});

  const getTodayISO = () => {
    const now = new Date();
    const y = now.getFullYear();
    const m = String(now.getMonth() + 1).padStart(2, "0");
    const d = String(now.getDate()).padStart(2, "0");
    return `${y}-${m}-${d}`;
  };

  const todayISO = getTodayISO();

  const startDateTime = startDate && startTime ? `${startDate} ${startTime}` : "";
  const endDateTime = endDate && endTime ? `${endDate} ${endTime}` : "";

  const isStartInPast = Boolean(startDate && startDate < todayISO);
  const isRangeInvalid = Boolean(startDateTime && endDateTime && endDateTime <= startDateTime);

  const startError = errors.from || (isStartInPast ? "Start date cannot be in the past." : null);
  const endError = errors.to || (isRangeInvalid ? "End date/time must be strictly after start date/time." : null);

  const isSubmitDisabled = isLoading || isStartInPast || isRangeInvalid;

  const validate = () => {
    const e = {};

    if (!startDate || !startTime) {
      e.from = "Please select start date and time";
    } else if (startDate < todayISO) {
      e.from = "Start date cannot be in the past.";
    }

    if (!endDate || !endTime) {
      e.to = "Please select end date and time";
    } else if (startDateTime && `${endDate} ${endTime}` <= startDateTime) {
      e.to = "End date/time must be strictly after start date/time.";
    }

    if (purpose.trim().length < 5) {
      e.purpose = "Please describe the purpose (min 5 chars)";
    }

    setErrors(e);
    return Object.keys(e).length === 0;
  };

  const handleSubmit = async () => {
    if (!validate()) return;

    setIsLoading(true);
    try {
      const result = await createBooking(token, {
        resource_type: "equipment",
        resource_id: equipmentId,
        allocation_date: startDate,
        end_date: endDate,
        start_time: startTime,
        end_time: endTime,
        purpose: purpose.trim(),
        priority,
      });

      setIsLoading(false);

      if (result.success) {
        Alert.alert(
          "Booking Submitted",
          'Your equipment booking is pending approval. Check "My Bookings" for updates.',
          [{ text: "OK", onPress: () => router.replace("/(tabs)/my-bookings") }],
        );
      } else {
        Alert.alert("Booking Failed", result.message || "Please try again.");
      }
    } catch {
      setIsLoading(false);
      Alert.alert("Connection Error", "Could not reach the server.");
    }
  };

  return (
    <SafeAreaView style={styles.safeArea}>
      <KeyboardAvoidingView
        style={styles.container}
        behavior={Platform.OS === "ios" ? "padding" : "height"}
      >
        <ScrollView
          contentContainerStyle={styles.scrollContent}
          keyboardShouldPersistTaps="handled"
        >
          <TouchableOpacity
            style={styles.backBtn}
            onPress={() => router.back()}
          >
            <Ionicons name="arrow-back-outline" size={24} color={COLORS.primary} />
            <Text style={styles.backText}>Back</Text>
          </TouchableOpacity>

          {imageUrl ? (
            <Image source={{ uri: imageUrl }} style={styles.resourceImage} resizeMode="cover" />
          ) : null}
          <Text style={styles.title}>Book Equipment</Text>
          <Text style={styles.subtitle}>{equipmentLabel}</Text>

          {/* Combined From (Start Date & Time) */}
          <CombinedDateTimePicker
            label="From"
            dateValue={startDate}
            timeValue={startTime}
            minimumDate={new Date()}
            onChange={({ date, time }) => {
              setStartDate(date);
              setStartTime(time);
              if (!endDate) {
                setEndDate(date);
              }
              setErrors((prev) => ({ ...prev, from: null }));
            }}
            placeholder="Select start date & time"
            error={startError}
            required
          />

          {/* Combined To (End Date & Time) */}
          <CombinedDateTimePicker
            label="To"
            dateValue={endDate}
            timeValue={endTime}
            minimumDate={startDate ? new Date(startDate) : new Date()}
            onChange={({ date, time }) => {
              setEndDate(date);
              setEndTime(time);
              setErrors((prev) => ({ ...prev, to: null }));
            }}
            placeholder="Select end date & time"
            error={endError}
            required
          />

          <View style={styles.group}>
            <Text style={styles.label}>Purpose</Text>
            <TextInput
              style={[styles.textArea, errors.purpose && styles.inputError]}
              value={purpose}
              onChangeText={setPurpose}
              placeholder="Why do you need this equipment?"
              placeholderTextColor={COLORS.textMuted}
              multiline
              numberOfLines={3}
            />
            {errors.purpose && (
              <Text style={styles.errorText}>{errors.purpose}</Text>
            )}
          </View>

          <View style={styles.group}>
            <Text style={styles.label}>Priority</Text>
            <View style={styles.row}>
              {["low", "medium", "high"].map((p) => (
                <TouchableOpacity
                  key={p}
                  style={[
                    styles.priorityBtn,
                    priority === p && styles.priorityBtnActive,
                  ]}
                  onPress={() => setPriority(p)}
                >
                  <Text
                    style={[
                      styles.priorityText,
                      priority === p && styles.priorityTextActive,
                    ]}
                  >
                    {p}
                  </Text>
                </TouchableOpacity>
              ))}
            </View>
          </View>

          <TouchableOpacity
            style={[styles.submit, isSubmitDisabled && styles.submitDisabled]}
            onPress={handleSubmit}
            disabled={isSubmitDisabled}
          >
            <Text style={styles.submitText}>
              {isLoading ? "Submitting…" : "Submit Booking"}
            </Text>
          </TouchableOpacity>
        </ScrollView>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

          <View style={styles.group}>
            <Text style={styles.label}>Purpose</Text>
            <TextInput
              style={[styles.textArea, errors.purpose && styles.inputError]}
              value={purpose}
              onChangeText={setPurpose}
              placeholder="Why do you need this equipment?"
              placeholderTextColor={COLORS.textMuted}
              multiline
              numberOfLines={3}
            />
            {errors.purpose && (
              <Text style={styles.errorText}>{errors.purpose}</Text>
            )}
          </View>

          <View style={styles.group}>
            <Text style={styles.label}>Priority</Text>
            <View style={styles.row}>
              {["low", "medium", "high"].map((p) => (
                <TouchableOpacity
                  key={p}
                  style={[
                    styles.priorityBtn,
                    priority === p && styles.priorityBtnActive,
                  ]}
                  onPress={() => setPriority(p)}
                >
                  <Text
                    style={[
                      styles.priorityText,
                      priority === p && styles.priorityTextActive,
                    ]}
                  >
                    {p}
                  </Text>
                </TouchableOpacity>
              ))}
            </View>
          </View>

          <TouchableOpacity
            style={[styles.submit, isSubmitDisabled && styles.submitDisabled]}
            onPress={handleSubmit}
            disabled={isSubmitDisabled}
          >
            <Text style={styles.submitText}>
              {isLoading ? "Submitting…" : "Submit Booking"}
            </Text>
          </TouchableOpacity>
        </ScrollView>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}


const styles = StyleSheet.create({
  safeArea: { flex: 1, backgroundColor: COLORS.surface },
  container: { flex: 1, backgroundColor: COLORS.surface },
  scrollContent: { paddingHorizontal: 24, paddingBottom: 40, paddingTop: 16 },
  backBtn: { flexDirection: "row", alignItems: "center", marginBottom: 12 },
  backText: { fontSize: 16, color: COLORS.primary, marginLeft: 4 },
  title: { fontSize: 26, fontWeight: "bold", color: COLORS.textPrimary },
  resourceImage: {
    width: "100%",
    height: 240,
    marginBottom: 16,
    borderRadius: 14,
    backgroundColor: COLORS.surfaceAlt,
  },
  subtitle: { fontSize: 15, color: COLORS.textSecondary, marginTop: 4, marginBottom: 24 },
  group: { marginBottom: 16 },
  label: { fontSize: 14, fontWeight: "600", color: COLORS.textSecondary, marginBottom: 6 },
  inputWrap: {
    flexDirection: "row",
    alignItems: "center",
    borderWidth: 1.5,
    borderColor: COLORS.border,
    borderRadius: 12,
    paddingHorizontal: 12,
    backgroundColor: COLORS.surfaceAlt,
  },
  input: { flex: 1, paddingVertical: 12, fontSize: 15, color: COLORS.textPrimary },
  textArea: {
    borderWidth: 1.5,
    borderColor: COLORS.border,
    borderRadius: 12,
    paddingHorizontal: 12,
    paddingVertical: 10,
    backgroundColor: COLORS.surfaceAlt,
    fontSize: 15,
    minHeight: 80,
    textAlignVertical: "top",
    color: COLORS.textPrimary,
  },
  inputError: { borderColor: COLORS.danger },
  errorText: { color: COLORS.danger, fontSize: 12, marginTop: 4 },
  row: { flexDirection: "row", gap: 12 },
  half: { flex: 1 },
  priorityBtn: {
    flex: 1,
    paddingVertical: 10,
    borderRadius: 10,
    borderWidth: 1.5,
    borderColor: COLORS.border,
    alignItems: "center",
    backgroundColor: COLORS.surfaceAlt,
  },
  priorityBtnActive: { backgroundColor: COLORS.primary, borderColor: COLORS.primary },
  priorityText: {
    fontSize: 14,
    color: COLORS.textSecondary,
    fontWeight: "600",
    textTransform: "capitalize",
  },
  priorityTextActive: { color: COLORS.surface },
  submit: {
    marginTop: 16,
    backgroundColor: COLORS.primary,
    paddingVertical: 16,
    borderRadius: 12,
    alignItems: "center",
  },
  submitDisabled: { opacity: 0.6 },
  submitText: { color: COLORS.surface, fontSize: 16, fontWeight: "600" },
});
