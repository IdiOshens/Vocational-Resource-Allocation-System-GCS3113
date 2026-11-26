import { useEffect, useState } from "react";
import * as ImagePicker from "expo-image-picker";
import {
  Alert,
  Image,
  ScrollView,
  StyleSheet,
  Switch,
  Text,
  TouchableOpacity,
  View,
} from "react-native";
import { useLocalSearchParams, useRouter } from "expo-router";
import { SafeAreaView } from "react-native-safe-area-context";
import ResourceScreenHeader from "../../../components/adminResources/ResourceScreenHeader";
import SelectField from "../../../components/adminResources/SelectField";
import CustomInput from "../../../components/ui/CustomInput";
import PrimaryButton from "../../../components/ui/PrimaryButton";
import { COLORS, SPACING } from "../../../constants/colors";
import { useAuth } from "../../../contexts/AuthContext";
import {
  createClassroom,
  fetchDepartments,
  uploadResourceImage,
  updateClassroom,
} from "../../../services/api/adminResources";

const ROOM_TYPES = [
  { label: "Classroom", value: "classroom" },
  { label: "Lecture Hall", value: "lecture_hall" },
  { label: "Laboratory", value: "laboratory" },
  { label: "Seminar Room", value: "seminar_room" },
  { label: "Conference Room", value: "conference_room" },
];
const STATUSES = [
  { label: "Available", value: "available" },
  { label: "Unavailable", value: "unavailable" },
  { label: "Maintenance", value: "maintenance" },
];
const FEATURE_FIELDS = [
  ["has_projector", "Projector"],
  ["has_smart_board", "Smart board"],
  ["has_computers", "Computers"],
  ["has_wifi", "Wi-Fi"],
  ["has_air_conditioning", "Air conditioning"],
];

function paramValue(value, fallback = "") {
  return Array.isArray(value) ? value[0] ?? fallback : value ?? fallback;
}

export default function ClassroomFormScreen() {
  const router = useRouter();
  const params = useLocalSearchParams();
  const { token } = useAuth();
  const id = Array.isArray(params.id) ? params.id[0] : params.id;
  const [classroomId, setClassroomId] = useState(id);
  const editing = Boolean(classroomId);
  const [roomNumber, setRoomNumber] = useState(paramValue(params.room_number));
  const [building, setBuilding] = useState(paramValue(params.building));
  const [capacity, setCapacity] = useState(paramValue(params.capacity));
  const [departmentId, setDepartmentId] = useState(
    paramValue(params.department_id ?? params.responsible_department_id),
  );
  const [departments, setDepartments] = useState([]);
  const [roomType, setRoomType] = useState(paramValue(params.room_type));
  const [status, setStatus] = useState(paramValue(params.status, "available"));
  const [features, setFeatures] = useState(() =>
    Object.fromEntries(
      FEATURE_FIELDS.map(([field]) => [
        field,
        paramValue(params[field], "0") === "1",
      ]),
    ),
  );
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState(null);
  const [profileImageUrl, setProfileImageUrl] = useState(
    paramValue(params.profile_image_url),
  );
  const [selectedImageUri, setSelectedImageUri] = useState(null);

  useEffect(() => {
    let mounted = true;
    const loadDepartments = async () => {
      if (!token) return;
      try {
        const result = await fetchDepartments(token);
        if (!result.success || !Array.isArray(result.data)) {
          throw new Error(result.message || "Could not load departments.");
        }
        if (mounted) setDepartments(result.data);
      } catch (loadError) {
        if (mounted) setError(loadError.message || "Could not load departments.");
      }
    };
    loadDepartments();
    return () => {
      mounted = false;
    };
  }, [token]);

  const pickImage = async () => {
    try {
      const permission = await ImagePicker.requestMediaLibraryPermissionsAsync();
      if (!permission.granted) {
        Alert.alert("Permission Required", "Allow photo library access to choose a resource image.");
        return;
      }
      const result = await ImagePicker.launchImageLibraryAsync({
        mediaTypes: ["images"],
        allowsEditing: true,
        quality: 0.8,
      });
      if (!result.canceled && result.assets?.[0]?.uri) {
        setSelectedImageUri(result.assets[0].uri);
      }
    } catch (pickerError) {
      Alert.alert("Image Selection Failed", pickerError.message || "Could not open your photo library.");
    }
  };

  const save = async () => {
    if (!roomNumber.trim() || !building.trim() || !capacity.trim() || !roomType) {
      setError("Complete all required fields.");
      return;
    }
    const parsedCapacity = Number(capacity);
    if (!Number.isInteger(parsedCapacity) || parsedCapacity <= 0) {
      setError("Capacity must be a positive whole number.");
      return;
    }

    setSaving(true);
    setError(null);
    try {
      const selectedDepartment = departments.find(
        (department) => String(department.department_id) === departmentId,
      );
      const data = {
        room_number: roomNumber.trim(),
        building: (selectedDepartment?.building || building).trim(),
        department_id: departmentId ? Number(departmentId) : null,
        floor: Number(paramValue(params.floor, "0")),
        campus_location: paramValue(params.campus_location),
        capacity: parsedCapacity,
        room_type: roomType,
        status,
        ...Object.fromEntries(
          FEATURE_FIELDS.map(([field]) => [field, Number(features[field])]),
        ),
        number_of_computers: Number(paramValue(params.number_of_computers, "0")),
      };
      const result = editing
        ? await updateClassroom(token, classroomId, data)
        : await createClassroom(token, data);
      if (!result.success) {
        throw new Error(result.message || "Could not save classroom.");
      }
      const savedClassroomId = editing ? classroomId : result.data?.classroom_id;
      if (!savedClassroomId) {
        throw new Error("Classroom saved, but its ID was not returned. Reload the resource list before retrying.");
      }
      if (!editing) setClassroomId(String(savedClassroomId));

      if (selectedImageUri) {
        const uploadResult = await uploadResourceImage(
          token,
          "classroom",
          savedClassroomId,
          selectedImageUri,
        );
        if (!uploadResult.success || !uploadResult.data?.profile_image_url) {
          throw new Error(
            `Classroom was saved, but image upload failed: ${uploadResult.message || "Please retry."}`,
          );
        }
        setProfileImageUrl(uploadResult.data.profile_image_url);
        setSelectedImageUri(null);
      }
      Alert.alert("Saved", editing ? "Classroom updated." : "Classroom created.", [
        {
          text: "OK",
          onPress: () => router.replace("/(tabs)/admin-resources/classrooms"),
        },
      ]);
    } catch (saveError) {
      setError(saveError.message || "Could not save classroom.");
    } finally {
      setSaving(false);
    }
  };

  return (
    <SafeAreaView style={styles.container}>
      <ResourceScreenHeader
        title={editing ? "Edit Classroom" : "Add Classroom"}
        backTo="/(tabs)/admin-resources"
      />
      <ScrollView contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">
        <CustomInput
          label="Room Number"
          value={roomNumber}
          onChangeText={setRoomNumber}
          placeholder="e.g. A101"
          icon="business-outline"
          required
        />
        <CustomInput
          label="Building"
          value={building}
          onChangeText={setBuilding}
          placeholder="Building name"
          icon="business-outline"
          required
        />
        <SelectField
          label="Department"
          value={departmentId}
          options={[
            { label: "Shared / Unassigned", value: "" },
            ...departments.map((department) => ({
              label: department.department_name,
              value: String(department.department_id),
            })),
          ]}
          onChange={(value) => {
            setDepartmentId(value);
            const selected = departments.find(
              (department) => String(department.department_id) === value,
            );
            if (selected?.building) setBuilding(selected.building);
          }}
        />
        <Text style={styles.helpText}>
          Department resources are visible to that department. Shared resources are visible to everyone.
        </Text>
        {(selectedImageUri || profileImageUrl) ? (
          <Image
            source={{ uri: selectedImageUri || profileImageUrl }}
            style={styles.resourceImage}
            resizeMode="cover"
          />
        ) : null}
        <TouchableOpacity style={styles.imageButton} onPress={pickImage}>
          <Text style={styles.imageButtonText}>
            {selectedImageUri || profileImageUrl ? "Change Resource Image" : "Add Resource Image"}
          </Text>
        </TouchableOpacity>
        <CustomInput
          label="Capacity"
          value={capacity}
          onChangeText={setCapacity}
          placeholder="Number of seats"
          icon="people-outline"
          keyboardType="number-pad"
          required
        />
        <SelectField
          label="Room Type"
          value={roomType}
          options={ROOM_TYPES}
          onChange={setRoomType}
        />
        <SelectField
          label="Status"
          value={status}
          options={STATUSES}
          onChange={setStatus}
        />
        <View style={styles.features}>
          <Text style={styles.featuresTitle}>Room Features</Text>
          {FEATURE_FIELDS.map(([field, label]) => (
            <View key={field} style={styles.featureRow}>
              <Text style={styles.featureLabel}>{label}</Text>
              <Switch
                value={features[field]}
                onValueChange={(value) =>
                  setFeatures((current) => ({ ...current, [field]: value }))
                }
                trackColor={{ false: COLORS.border, true: COLORS.lightBlue }}
                thumbColor={features[field] ? COLORS.primary : COLORS.surface}
                accessibilityLabel={label}
              />
            </View>
          ))}
        </View>
        {error ? <Text style={styles.error}>{error}</Text> : null}
        <PrimaryButton
          title={editing ? "Save Changes" : "Create Classroom"}
          onPress={save}
          loading={saving}
          style={styles.saveButton}
        />
      </ScrollView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: COLORS.background },
  content: { paddingHorizontal: 20, paddingBottom: SPACING.xl },
  error: { marginBottom: SPACING.sm, color: COLORS.danger, fontSize: 13 },
  helpText: { marginTop: -SPACING.sm, marginBottom: SPACING.md, color: COLORS.textMuted, fontSize: 12 },
  resourceImage: {
    width: "100%",
    height: 180,
    marginBottom: SPACING.sm,
    borderRadius: 12,
    backgroundColor: COLORS.surfaceAlt,
  },
  imageButton: {
    alignSelf: "flex-start",
    marginBottom: SPACING.md,
    paddingVertical: SPACING.sm,
    paddingHorizontal: SPACING.md,
    borderRadius: 8,
    backgroundColor: COLORS.surfaceAlt,
  },
  imageButtonText: { color: COLORS.primary, fontWeight: "700" },
  saveButton: { marginTop: SPACING.sm },
  features: {
    marginBottom: SPACING.md,
    paddingHorizontal: SPACING.md,
    borderRadius: 12,
    backgroundColor: COLORS.surface,
  },
  featuresTitle: {
    paddingTop: SPACING.sm,
    color: COLORS.textPrimary,
    fontSize: 15,
    fontWeight: "700",
  },
  featureRow: {
    minHeight: 48,
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "space-between",
    borderBottomWidth: 1,
    borderBottomColor: COLORS.surfaceAlt,
  },
  featureLabel: { color: COLORS.textSecondary, fontSize: 14 },
});
