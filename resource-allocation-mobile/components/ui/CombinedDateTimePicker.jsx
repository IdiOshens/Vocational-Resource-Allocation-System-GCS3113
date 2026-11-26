import { Ionicons } from "@expo/vector-icons";
import DateTimePicker from "@react-native-community/datetimepicker";
import React, { useState } from "react";
import {
  Modal,
  Platform,
  StyleSheet,
  Text,
  TouchableOpacity,
  View,
} from "react-native";
import { COLORS } from "../../constants/colors";

function formatDateToISO(date) {
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, "0");
  const d = String(date.getDate()).padStart(2, "0");
  return `${y}-${m}-${d}`;
}

function formatTimeToHHMM(date) {
  const h = String(date.getHours()).padStart(2, "0");
  const m = String(date.getMinutes()).padStart(2, "0");
  return `${h}:${m}`;
}

function formatDisplayDateTime(dateStr, timeStr) {
  if (!dateStr) return "";
  const parts = dateStr.split("-");
  if (parts.length !== 3) return dateStr;
  const year = parseInt(parts[0], 10);
  const month = parseInt(parts[1], 10) - 1;
  const day = parseInt(parts[2], 10);
  const d = new Date(year, month, day);
  if (isNaN(d.getTime())) return dateStr;

  const formattedDate = d.toLocaleDateString("en-GB", {
    day: "numeric",
    month: "short",
    year: "numeric",
  });
  return timeStr ? `${formattedDate} at ${timeStr}` : formattedDate;
}

function buildDateObject(dateStr, timeStr) {
  const now = new Date();
  if (dateStr && /^\d{4}-\d{2}-\d{2}$/.test(dateStr)) {
    const [y, m, d] = dateStr.split("-").map((v) => parseInt(v, 10));
    now.setFullYear(y, m - 1, d);
  }
  if (timeStr && /^\d{1,2}:\d{2}$/.test(timeStr)) {
    const [h, min] = timeStr.split(":").map((v) => parseInt(v, 10));
    now.setHours(h, min, 0, 0);
  } else {
    now.setSeconds(0, 0);
  }
  return now;
}

export default function CombinedDateTimePicker({
  label,
  dateValue,
  timeValue,
  onChange,
  error,
  required = false,
  placeholder = "Select date & time",
  minimumDate,
}) {
  const [showModal, setShowModal] = useState(false);
  const [pickerMode, setPickerMode] = useState("date"); // 'date' or 'time'
  const [tempDate, setTempDate] = useState(new Date());

  const handleOpen = () => {
    setTempDate(buildDateObject(dateValue, timeValue));
    setPickerMode("date");
    setShowModal(true);
  };

  const handleAndroidChange = (event, selected) => {
    if (event.type === "dismissed") {
      setShowModal(false);
      return;
    }
    if (selected) {
      if (pickerMode === "date") {
        setTempDate(selected);
        // Switch to time picker
        setPickerMode("time");
      } else {
        // Time picked
        setShowModal(false);
        const isoDate = formatDateToISO(tempDate);
        const hhmm = formatTimeToHHMM(selected);
        onChange({ date: isoDate, time: hhmm });
      }
    }
  };

  const handleIosConfirm = () => {
    const isoDate = formatDateToISO(tempDate);
    const hhmm = formatTimeToHHMM(tempDate);
    onChange({ date: isoDate, time: hhmm });
    setShowModal(false);
  };

  const displayText = dateValue ? formatDisplayDateTime(dateValue, timeValue) : "";

  return (
    <View style={styles.container}>
      {label && (
        <Text style={styles.label}>
          {label}
          {required && <Text style={styles.required}> *</Text>}
        </Text>
      )}

      <TouchableOpacity
        activeOpacity={0.7}
        style={[styles.inputWrapper, error && styles.inputError]}
        onPress={handleOpen}
      >
        <Ionicons
          name="calendar-outline"
          size={20}
          color={COLORS.textMuted}
          style={styles.icon}
        />
        <Text style={[styles.text, !displayText && styles.placeholderText]}>
          {displayText || placeholder}
        </Text>
        <Ionicons name="time-outline" size={18} color={COLORS.textMuted} />
      </TouchableOpacity>

      {error && <Text style={styles.errorText}>{error}</Text>}

      {/* Android: Sequential native dialogs (Date then Time) */}
      {Platform.OS === "android" && showModal && (
        <DateTimePicker
          value={tempDate}
          mode={pickerMode}
          is24Hour={true}
          display="default"
          minimumDate={minimumDate}
          onChange={handleAndroidChange}
        />
      )}

      {/* iOS: Bottom sheet modal with tabs or switch for Date & Time */}
      {Platform.OS === "ios" && (
        <Modal
          visible={showModal}
          transparent={true}
          animationType="fade"
          onRequestClose={() => setShowModal(false)}
        >
          <TouchableOpacity
            style={styles.modalOverlay}
            activeOpacity={1}
            onPress={() => setShowModal(false)}
          >
            <View
              style={styles.modalContent}
              onStartShouldSetResponder={() => true}
            >
              <View style={styles.modalHeader}>
                <TouchableOpacity
                  onPress={() => setShowModal(false)}
                  style={styles.headerButton}
                >
                  <Text style={styles.cancelText}>Cancel</Text>
                </TouchableOpacity>
                <Text style={styles.modalTitle}>{label || "Select Date & Time"}</Text>
                <TouchableOpacity
                  onPress={handleIosConfirm}
                  style={styles.headerButton}
                >
                  <Text style={styles.doneText}>Done</Text>
                </TouchableOpacity>
              </View>

              <View style={styles.modeTabs}>
                <TouchableOpacity
                  style={[
                    styles.modeTab,
                    pickerMode === "date" && styles.modeTabActive,
                  ]}
                  onPress={() => setPickerMode("date")}
                >
                  <Text
                    style={[
                      styles.modeTabText,
                      pickerMode === "date" && styles.modeTabTextActive,
                    ]}
                  >
                    Date
                  </Text>
                </TouchableOpacity>
                <TouchableOpacity
                  style={[
                    styles.modeTab,
                    pickerMode === "time" && styles.modeTabActive,
                  ]}
                  onPress={() => setPickerMode("time")}
                >
                  <Text
                    style={[
                      styles.modeTabText,
                      pickerMode === "time" && styles.modeTabTextActive,
                    ]}
                  >
                    Time
                  </Text>
                </TouchableOpacity>
              </View>

              <DateTimePicker
                value={tempDate}
                mode={pickerMode}
                display="spinner"
                is24Hour={true}
                minimumDate={minimumDate}
                onChange={(_, selected) => {
                  if (selected) setTempDate(selected);
                }}
                style={styles.iosPicker}
              />
            </View>
          </TouchableOpacity>
        </Modal>
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    marginBottom: 16,
  },
  label: {
    fontSize: 14,
    fontWeight: "600",
    color: COLORS.textSecondary,
    marginBottom: 6,
  },
  required: {
    color: COLORS.danger,
  },
  inputWrapper: {
    flexDirection: "row",
    alignItems: "center",
    borderWidth: 1.5,
    borderColor: COLORS.border,
    borderRadius: 12,
    paddingHorizontal: 14,
    paddingVertical: 14,
    backgroundColor: COLORS.surfaceAlt,
  },
  icon: {
    marginRight: 10,
  },
  text: {
    flex: 1,
    fontSize: 15,
    color: COLORS.textPrimary,
  },
  placeholderText: {
    color: COLORS.textMuted,
  },
  inputError: {
    borderColor: COLORS.danger,
    backgroundColor: COLORS.dangerBg,
  },
  errorText: {
    color: COLORS.danger,
    fontSize: 12,
    marginTop: 4,
    marginLeft: 4,
  },
  modalOverlay: {
    flex: 1,
    backgroundColor: "rgba(0,0,0,0.45)",
    justifyContent: "flex-end",
  },
  modalContent: {
    backgroundColor: COLORS.surface,
    borderTopLeftRadius: 20,
    borderTopRightRadius: 20,
    paddingBottom: 24,
  },
  modalHeader: {
    flexDirection: "row",
    justifyContent: "space-between",
    alignItems: "center",
    paddingHorizontal: 20,
    paddingVertical: 14,
    borderBottomWidth: 1,
    borderBottomColor: COLORS.border,
  },
  headerButton: {
    paddingVertical: 4,
    paddingHorizontal: 8,
  },
  modalTitle: {
    fontSize: 16,
    fontWeight: "600",
    color: COLORS.textPrimary,
  },
  cancelText: {
    fontSize: 16,
    color: COLORS.textMuted,
  },
  doneText: {
    fontSize: 16,
    fontWeight: "600",
    color: COLORS.primary,
  },
  modeTabs: {
    flexDirection: "row",
    paddingHorizontal: 20,
    paddingTop: 12,
    gap: 10,
  },
  modeTab: {
    flex: 1,
    paddingVertical: 8,
    borderRadius: 8,
    alignItems: "center",
    backgroundColor: COLORS.surfaceAlt,
  },
  modeTabActive: {
    backgroundColor: COLORS.primary,
  },
  modeTabText: {
    fontSize: 14,
    fontWeight: "600",
    color: COLORS.textSecondary,
  },
  modeTabTextActive: {
    color: COLORS.surface,
  },
  iosPicker: {
    height: 180,
  },
});
