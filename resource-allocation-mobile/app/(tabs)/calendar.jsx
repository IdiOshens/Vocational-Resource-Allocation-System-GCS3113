import { Ionicons } from "@expo/vector-icons";
import { useCallback, useEffect, useState } from "react";
import {
  ActivityIndicator,
  FlatList,
  StyleSheet,
  Text,
  TouchableOpacity,
  View,
} from "react-native";
import { SafeAreaView } from "react-native-safe-area-context";
import { Calendar } from "react-native-calendars";
import { useRouter } from "expo-router";
import { COLORS, RADIUS, SPACING } from "../../constants/colors";
import { useAuth } from "../../contexts/AuthContext";
import {
  fetchAllBookings,
  fetchMyBookings,
} from "../../services/api/bookings";

const STATUS_COLORS = {
  pending: COLORS.warning,
  approved: COLORS.success,
  rejected: COLORS.danger,
  cancelled: COLORS.textMuted,
  completed: COLORS.textSecondary,
};

function toDateStr(dateVal) {
  if (!dateVal) return null;
  return String(dateVal).substring(0, 10);
}

export default function CalendarScreen() {
  const router = useRouter();
  const { token, role } = useAuth();

  const [bookings, setBookings] = useState([]);
  const [loading, setLoading] = useState(true);
  const [selectedDate, setSelectedDate] = useState(
    new Date().toISOString().substring(0, 10)
  );

  const loadBookings = useCallback(async () => {
    if (!token) return;
    setLoading(true);
    try {
      const res =
        role === "admin" || role === "department_head"
          ? await fetchAllBookings(token)
          : await fetchMyBookings(token);
      if (res.success && Array.isArray(res.data)) {
        setBookings(res.data);
      }
    } catch {
      // Soft fail
    } finally {
      setLoading(false);
    }
  }, [role, token]);

  useEffect(() => {
    loadBookings();
  }, [loadBookings]);

  // Build markedDates object
  const markedDates = {};
  bookings.forEach((b) => {
    const d = toDateStr(b.allocation_date);
    if (!d) return;
    const color = STATUS_COLORS[b.status] ?? COLORS.primary;
    if (!markedDates[d]) {
      markedDates[d] = { dots: [] };
    }
    // Keep at most 3 dots per day
    if (markedDates[d].dots.length < 3) {
      markedDates[d].dots.push({ key: String(b.allocation_id), color });
    }
  });

  // Highlight selected date
  markedDates[selectedDate] = {
    ...(markedDates[selectedDate] || {}),
    selected: true,
    selectedColor: COLORS.primary,
    dots: markedDates[selectedDate]?.dots || [],
  };

  const dayBookings = bookings.filter(
    (b) => toDateStr(b.allocation_date) === selectedDate
  );

  const renderItem = ({ item }) => {
    const statusColor = STATUS_COLORS[item.status] ?? COLORS.textMuted;
    return (
      <TouchableOpacity
        style={styles.card}
        onPress={() =>
          router.push({
            pathname: "/(tabs)/booking-detail",
            params: { id: item.allocation_id },
          })
        }
        accessibilityRole="button"
      >
        <View style={styles.cardLeft}>
          <View style={[styles.dot, { backgroundColor: statusColor }]} />
        </View>
        <View style={styles.cardBody}>
          <Text style={styles.cardTitle} numberOfLines={1}>
            {item.resource_name ?? item.resource_type}
          </Text>
          <Text style={styles.cardSub}>
            {item.start_time?.substring(0, 5)} – {item.end_time?.substring(0, 5)}
            {"  "}
            <Text style={[styles.cardStatus, { color: statusColor }]}>
              {item.status}
            </Text>
          </Text>
          {item.purpose ? (
            <Text style={styles.cardPurpose} numberOfLines={1}>
              {item.purpose}
            </Text>
          ) : null}
        </View>
        <Ionicons name="chevron-forward" size={16} color={COLORS.textMuted} />
      </TouchableOpacity>
    );
  };

  return (
    <SafeAreaView style={styles.safe}>
      <View style={styles.header}>
        <TouchableOpacity onPress={() => router.back()} style={styles.backBtn}>
          <Ionicons name="arrow-back" size={22} color={COLORS.primary} />
        </TouchableOpacity>
        <Text style={styles.title}>Booking Calendar</Text>
        <TouchableOpacity onPress={loadBookings} style={styles.backBtn}>
          <Ionicons name="refresh-outline" size={20} color={COLORS.primary} />
        </TouchableOpacity>
      </View>

      {loading ? (
        <ActivityIndicator
          color={COLORS.primary}
          size="large"
          style={{ marginTop: 40 }}
        />
      ) : (
        <FlatList
          data={dayBookings}
          keyExtractor={(item) => String(item.allocation_id)}
          renderItem={renderItem}
          contentContainerStyle={styles.list}
          keyboardShouldPersistTaps="handled"
          ListHeaderComponent={
            <>
              <Calendar
                markingType="multi-dot"
                markedDates={markedDates}
                onDayPress={(day) => setSelectedDate(day.dateString)}
                theme={{
                  todayTextColor: COLORS.primary,
                  selectedDayBackgroundColor: COLORS.primary,
                  arrowColor: COLORS.primary,
                  dotColor: COLORS.primary,
                  monthTextColor: COLORS.textPrimary,
                  dayTextColor: COLORS.textPrimary,
                  textDisabledColor: COLORS.textMuted,
                  calendarBackground: COLORS.surface,
                }}
                style={styles.calendar}
              />

              <View style={styles.dayHeader}>
                <Text style={styles.dayTitle}>
                  {selectedDate === new Date().toISOString().substring(0, 10)
                    ? "Today"
                    : selectedDate}
                </Text>
                <Text style={styles.dayCount}>
                  {dayBookings.length} booking
                  {dayBookings.length !== 1 ? "s" : ""}
                </Text>
              </View>

              {dayBookings.length === 0 ? (
                <Text style={styles.emptyText}>No bookings on this day.</Text>
              ) : null}
            </>
          }
        />
      )}
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
  calendar: {
    borderBottomWidth: 1,
    borderBottomColor: COLORS.border,
    marginBottom: SPACING.sm,
  },
  list: { paddingBottom: SPACING.xl },
  dayHeader: {
    flexDirection: "row",
    justifyContent: "space-between",
    alignItems: "center",
    paddingHorizontal: SPACING.md,
    paddingVertical: SPACING.sm,
  },
  dayTitle: { fontSize: 15, fontWeight: "700", color: COLORS.textPrimary },
  dayCount: { fontSize: 13, color: COLORS.textSecondary },
  emptyText: {
    textAlign: "center",
    color: COLORS.textMuted,
    marginTop: SPACING.md,
    paddingHorizontal: SPACING.md,
  },
  card: {
    flexDirection: "row",
    alignItems: "center",
    backgroundColor: COLORS.surface,
    marginHorizontal: SPACING.md,
    marginBottom: SPACING.sm,
    borderRadius: RADIUS.md,
    padding: SPACING.md,
    borderWidth: 1,
    borderColor: COLORS.border,
  },
  cardLeft: { width: 20, alignItems: "center", marginRight: SPACING.sm },
  dot: { width: 10, height: 10, borderRadius: 5 },
  cardBody: { flex: 1 },
  cardTitle: { fontSize: 14, fontWeight: "700", color: COLORS.textPrimary },
  cardSub: { fontSize: 12, color: COLORS.textSecondary, marginTop: 2 },
  cardStatus: { fontWeight: "600", textTransform: "capitalize" },
  cardPurpose: { fontSize: 12, color: COLORS.textMuted, marginTop: 2 },
});
