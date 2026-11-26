import * as ImagePicker from "expo-image-picker";
import {
  Alert,
  Image,
  ScrollView,
  StyleSheet,
  Text,
  TouchableOpacity,
} from "react-native";
import { useEffect, useState } from "react";
import { useLocalSearchParams, useRouter } from "expo-router";
import { SafeAreaView } from "react-native-safe-area-context";
import ResourceScreenHeader from "../../../components/adminResources/ResourceScreenHeader";
import SelectField from "../../../components/adminResources/SelectField";
import CustomInput from "../../../components/ui/CustomInput";
import PrimaryButton from "../../../components/ui/PrimaryButton";
import { COLORS, SPACING } from "../../../constants/colors";
import { useAuth } from "../../../contexts/AuthContext";
import {
  createEquipment,
  fetchDepartments,
  uploadResourceImage,
  updateEquipment,
} from "../../../services/api/adminResources";

const SAFETY_LEVELS = [
  { label: "Low", value: "low" },
  { label: "Medium", value: "medium" },
  { label: "High", value: "high" },
];

function paramValue(value, fallback = "") {
  return Array.isArray(value) ? value[0] ?? fallback : value ?? fallback;
}

export default function EquipmentFormScreen() {
  const router = useRouter();
  const params = useLocalSearchParams();
  const { token } = useAuth();
  const id = paramValue(params.id);
  const [equipmentId, setEquipmentId] = useState(id);
  const editing = Boolean(equipmentId);
  const [name, setName] = useState(paramValue(params.equipment_name));
  const [type, setType] = useState(paramValue(params.equipment_type));
  const [model, setModel] = useState(paramValue(params.model));
  const [serialNumber, setSerialNumber] = useState(paramValue(params.serial_number));
  const [location, setLocation] = useState(paramValue(params.current_location));
  const [batteryHealth, setBatteryHealth] = useState(
    paramValue(params.battery_health),
  );
  const [safetyLevel, setSafetyLevel] = useState(
    paramValue(params.safety_level, "low"),
  );
  const [departmentId, setDepartmentId] = useState(
    paramValue(params.responsible_department_id),
  );
  const [departments, setDepartments] = useState([]);
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
    if (!name.trim() || !type.trim()) {
      setError("Equipment Name and Type are required.");
      return;
    }
    const parsedBatteryHealth =
      batteryHealth.trim() === "" ? null : Number(batteryHealth);
    if (
      parsedBatteryHealth !== null &&
      (!Number.isInteger(parsedBatteryHealth) ||
        parsedBatteryHealth < 0 ||
        parsedBatteryHealth > 100)
    ) {
      setError("Battery health must be a whole number from 0 to 100.");
      return;
    }
    setSaving(true);
    setError(null);
    try {
      const data = {
        equipment_name: name.trim(),
        equipment_type: type.trim(),
        model: model.trim(),
        serial_number: serialNumber.trim(),
        current_location: location.trim(),
        battery_health: parsedBatteryHealth,
        safety_level: safetyLevel,
        responsible_department_id: departmentId ? Number(departmentId) : null,
      };
      const result = editing
        ? await updateEquipment(token, equipmentId, data)
        : await createEquipment(token, data);
      if (!result.success) {
        throw new Error(result.message || "Could not save equipment.");
      }
      const savedEquipmentId = editing ? equipmentId : result.data?.equipment_id;
      if (!savedEquipmentId) {
        throw new Error("Equipment saved, but its ID was not returned. Reload the resource list before retrying.");
      }
      if (!editing) setEquipmentId(String(savedEquipmentId));

      if (selectedImageUri) {
        const uploadResult = await uploadResourceImage(
          token,
          "equipment",
          savedEquipmentId,
          selectedImageUri,
        );
        if (!uploadResult.success || !uploadResult.data?.profile_image_url) {
          throw new Error(
            `Equipment was saved, but image upload failed: ${uploadResult.message || "Please retry."}`,
          );
        }
        setProfileImageUrl(uploadResult.data.profile_image_url);
        setSelectedImageUri(null);
      }
      Alert.alert("Saved", editing ? "Equipment updated." : "Equipment created.", [
        {
          text: "OK",
          onPress: () => router.replace("/(tabs)/admin-resources/equipment"),
        },
      ]);
    } catch (saveError) {
      setError(saveError.message || "Could not save equipment.");
    } finally {
      setSaving(false);
    }
  };

  return (
    <SafeAreaView style={styles.container}>
      <ResourceScreenHeader
        title={editing ? "Edit Equipment" : "Add Equipment"}
        backTo="/(tabs)/admin-resources"
      />
      <ScrollView contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">
        <CustomInput
          label="Equipment Name"
          value={name}
          onChangeText={setName}
          placeholder="Equipment name"
          icon="construct-outline"
          required
        />
        <CustomInput
          label="Type"
          value={type}
          onChangeText={setType}
          placeholder="e.g. Projector"
          icon="pricetag-outline"
          required
        />
        <CustomInput
          label="Model"
          value={model}
          onChangeText={setModel}
          placeholder="Model"
          icon="hardware-chip-outline"
        />
        <CustomInput
          label="Serial Number"
          value={serialNumber}
          onChangeText={setSerialNumber}
          placeholder="Serial number"
          icon="barcode-outline"
          autoCapitalize="characters"
        />
        <CustomInput
          label="Current Location"
          value={location}
          onChangeText={setLocation}
          placeholder="Location"
          icon="location-outline"
        />
        <CustomInput
          label="Battery Health (0-100)"
          value={batteryHealth}
          onChangeText={setBatteryHealth}
          placeholder="e.g. 95"
          icon="battery-half-outline"
          keyboardType="number-pad"
        />
        <SelectField
          label="Safety Level"
          value={safetyLevel}
          options={SAFETY_LEVELS}
          onChange={setSafetyLevel}
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
          onChange={setDepartmentId}
        />
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
        {error ? <Text style={styles.error}>{error}</Text> : null}
        <PrimaryButton
          title={editing ? "Save Changes" : "Create Equipment"}
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
});
