import { Ionicons } from "@expo/vector-icons";
import { useLocalSearchParams, useRouter } from "expo-router";
import { useState } from "react";
import {
  ActivityIndicator,
  Alert,
  FlatList,
  KeyboardAvoidingView,
  Modal,
  Platform,
  ScrollView,
  StyleSheet,
  Text,
  TouchableOpacity,
  View,
} from "react-native";
import { SafeAreaView } from "react-native-safe-area-context";

import CustomInput from "../../components/ui/CustomInput";
import PrimaryButton from "../../components/ui/PrimaryButton";
import ProgressIndicator from "../../components/ui/ProgressIndicator";
import { registerUser } from "../../services/api/auth";
import { API_BASE_URL } from "../../services/api/config";
import { COLORS } from "../../constants/colors";

// Each department has an id that matches the departments table.
const DEPARTMENTS = [
  { id: 1, name: "Information Technology" },
  { id: 2, name: "Electrical Engineering" },
  { id: 3, name: "Mechanical Engineering" },
  { id: 4, name: "Business Studies" },
  { id: 5, name: "Building and Civil Engineering" },
  { id: 6, name: "Agriculture" },
];

// Roles allowed for public registration. Value must match the backend enum.
const ROLES = [
  { label: "Student", value: "student" },
  { label: "Instructor", value: "instructor" },
  { label: "Staff", value: "staff" },
];

export default function Step2Screen() {
  const router = useRouter();
  const params = useLocalSearchParams();

  // The Step 1 data was forwarded as a JSON string in `params.userData`.
  const step1Data = params.userData ? JSON.parse(params.userData) : {};

  const [phoneNumber, setPhoneNumber] = useState("");
  const [selectedRole, setSelectedRole] = useState("student");
  const [selectedDepartment, setSelectedDepartment] = useState(null);
  const [showDepartmentDropdown, setShowDepartmentDropdown] = useState(false);

  const [courses, setCourses] = useState([]);
  const [selectedCourse, setSelectedCourse] = useState(null);
  const [loadingCourses, setLoadingCourses] = useState(false);
  const [showCourseDropdown, setShowCourseDropdown] = useState(false);

  const [isLoading, setIsLoading] = useState(false);
  const [errors, setErrors] = useState({});

  const fetchCourses = async (departmentId) => {
    setLoadingCourses(true);
    setCourses([]);
    setSelectedCourse(null);
    try {
      const res = await fetch(`${API_BASE_URL}?action=courses&department_id=${departmentId}`);
      const json = await res.json();
      if (json.success && Array.isArray(json.data)) {
        setCourses(json.data);
      }
    } catch {
      // ignore network errors - courses will be empty
    } finally {
      setLoadingCourses(false);
    }
  };

  const handleCreateAccount = async () => {
    const newErrors = {};

    if (!phoneNumber.trim()) {
      newErrors.phoneNumber = "Phone number is required";
    } else if (phoneNumber.trim().length < 10) {
      newErrors.phoneNumber = "Enter a valid phone number";
    }

    if (!selectedDepartment) {
      newErrors.department = "Please select a department";
    }

    if (selectedRole === "student" && !selectedCourse) {
      newErrors.course = "Please select a course";
    }

    setErrors(newErrors);

    if (Object.keys(newErrors).length !== 0) {
      return;
    }

    setIsLoading(true);

    // Build the payload the PHP API expects.
    const payload = {
      full_name: step1Data.fullName,
      email: step1Data.email,
      username: step1Data.username,
      password: step1Data.password,
      phone: phoneNumber.trim(),
      role: selectedRole,
      department_id: selectedDepartment.id,
      course_id: selectedRole === "student" ? selectedCourse?.course_id : null,
    };

    try {
      const result = await registerUser(payload);

      if (result.success) {
        setIsLoading(false);
        router.replace("/success");
      } else {
        setIsLoading(false);
        Alert.alert(
          "Registration Failed",
          result.message || "Please try again.",
        );
      }
    } catch (error) {
      setIsLoading(false);
      Alert.alert(
        "Connection Error",
        "Could not reach the server. Check that XAMPP is running and your phone is on the same WiFi as your PC.",
      );
    }
  };

  const renderDepartmentOption = ({ item }) => (
    <TouchableOpacity
      style={[
        styles.dropdownItem,
        selectedDepartment?.id === item.id && styles.dropdownItemSelected,
      ]}
      onPress={() => {
        setSelectedDepartment(item);
        setShowDepartmentDropdown(false);
        setErrors((prev) => ({ ...prev, department: null }));
        if (selectedRole === "student") {
          fetchCourses(item.id);
        }
      }}
    >
      <Text
        style={[
          styles.dropdownItemText,
          selectedDepartment?.id === item.id && styles.dropdownItemTextSelected,
        ]}
      >
        {item.name}
      </Text>
      {selectedDepartment?.id === item.id && (
        <Ionicons name="checkmark-circle" size={20} color={COLORS.primary} />
      )}
    </TouchableOpacity>
  );

  return (
    <SafeAreaView style={styles.safeArea}>
      <KeyboardAvoidingView
        style={styles.container}
        behavior={Platform.OS === "ios" ? "padding" : "height"}
      >
        <ScrollView
          contentContainerStyle={styles.scrollContent}
          showsVerticalScrollIndicator={false}
          keyboardShouldPersistTaps="handled"
        >
          <ProgressIndicator currentStep={2} totalSteps={2} />

          <TouchableOpacity
            style={styles.backButton}
            onPress={() => router.back()}
          >
            <Ionicons name="arrow-back-outline" size={24} color={COLORS.primary} />
            <Text style={styles.backText}>Back</Text>
          </TouchableOpacity>

          <View style={styles.header}>
            <Text style={styles.title}>Complete Your Profile</Text>
            <Text style={styles.subtitle}>Tell us a little about yourself</Text>
          </View>

          <View style={styles.form}>
            <CustomInput
              label="Phone Number"
              value={phoneNumber}
              onChangeText={setPhoneNumber}
              placeholder="Enter your phone number"
              icon="call-outline"
              keyboardType="phone-pad"
              error={errors.phoneNumber}
              required
            />

            <View style={styles.inputGroup}>
              <Text style={styles.label}>Role</Text>
              <View style={styles.roleContainer}>
                {ROLES.map((role) => (
                  <TouchableOpacity
                    key={role.value}
                    style={[
                      styles.roleOption,
                      selectedRole === role.value && styles.roleOptionSelected,
                    ]}
                    onPress={() => {
                      setSelectedRole(role.value);
                      setSelectedCourse(null);
                      setCourses([]);
                      setErrors((prev) => ({ ...prev, course: null }));
                      if (role.value === "student" && selectedDepartment) {
                        fetchCourses(selectedDepartment.id);
                      }
                    }}
                  >
                    <Text
                      style={[
                        styles.roleText,
                        selectedRole === role.value && styles.roleTextSelected,
                      ]}
                    >
                      {role.label}
                    </Text>
                  </TouchableOpacity>
                ))}
              </View>
            </View>

            <View style={styles.inputGroup}>
              <Text style={styles.label}>
                Department
                <Text style={styles.required}> *</Text>
              </Text>
              <TouchableOpacity
                style={[
                  styles.dropdownTrigger,
                  errors.department && styles.inputError,
                ]}
                onPress={() => setShowDepartmentDropdown(true)}
              >
                <Text
                  style={[
                    styles.dropdownTriggerText,
                    !selectedDepartment && styles.dropdownPlaceholder,
                  ]}
                >
                  {selectedDepartment?.name || "Select department"}
                </Text>
                <Ionicons name="chevron-down" size={20} color={COLORS.textMuted} />
              </TouchableOpacity>
              {errors.department && (
                <Text style={styles.errorText}>{errors.department}</Text>
              )}
            </View>

            {selectedRole === "student" && (
              <View style={styles.inputGroup}>
                <Text style={styles.label}>
                  Course
                  <Text style={styles.required}> *</Text>
                </Text>
                {loadingCourses ? (
                  <ActivityIndicator size="small" color={COLORS.primary} style={{ marginVertical: 12 }} />
                ) : (
                  <>
                    <TouchableOpacity
                      style={[
                        styles.dropdownTrigger,
                        errors.course && styles.inputError,
                      ]}
                      onPress={() => {
                        if (!selectedDepartment) {
                          Alert.alert("Select Department", "Please select a department first to see available courses.");
                          return;
                        }
                        setShowCourseDropdown(true);
                      }}
                    >
                      <Text
                        style={[
                          styles.dropdownTriggerText,
                          !selectedCourse && styles.dropdownPlaceholder,
                        ]}
                      >
                        {selectedCourse
                          ? `${selectedCourse.course_code} - ${selectedCourse.course_name}`
                          : selectedDepartment
                          ? courses.length > 0
                            ? "Select course"
                            : "No courses found for this department"
                          : "Select department first"}
                      </Text>
                      <Ionicons name="chevron-down" size={20} color={COLORS.textMuted} />
                    </TouchableOpacity>
                    {errors.course && (
                      <Text style={styles.errorText}>{errors.course}</Text>
                    )}
                  </>
                )}
              </View>
            )}

            <PrimaryButton
              title="Create Account"
              onPress={handleCreateAccount}
              loading={isLoading}
              type="secondary"
              style={styles.createButton}
            />
          </View>
        </ScrollView>
      </KeyboardAvoidingView>

      <Modal
        visible={showDepartmentDropdown}
        transparent
        animationType="fade"
        onRequestClose={() => setShowDepartmentDropdown(false)}
      >
        <TouchableOpacity
          style={styles.modalOverlay}
          activeOpacity={1}
          onPress={() => setShowDepartmentDropdown(false)}
        >
          <View style={styles.modalContent}>
            <View style={styles.modalHeader}>
              <Text style={styles.modalTitle}>Select Department</Text>
              <TouchableOpacity
                onPress={() => setShowDepartmentDropdown(false)}
              >
                <Ionicons name="close" size={24} color={COLORS.textSecondary} />
              </TouchableOpacity>
            </View>
            <FlatList
              data={DEPARTMENTS}
              renderItem={renderDepartmentOption}
              keyExtractor={(item) => String(item.id)}
              showsVerticalScrollIndicator={false}
            />
          </View>
        </TouchableOpacity>
      </Modal>

      <Modal
        visible={showCourseDropdown}
        transparent
        animationType="fade"
        onRequestClose={() => setShowCourseDropdown(false)}
      >
        <TouchableOpacity
          style={styles.modalOverlay}
          activeOpacity={1}
          onPress={() => setShowCourseDropdown(false)}
        >
          <View style={styles.modalContent}>
            <View style={styles.modalHeader}>
              <Text style={styles.modalTitle}>Select Course</Text>
              <TouchableOpacity onPress={() => setShowCourseDropdown(false)}>
                <Ionicons name="close" size={24} color={COLORS.textSecondary} />
              </TouchableOpacity>
            </View>
            <FlatList
              data={courses}
              keyExtractor={(item) => String(item.course_id)}
              showsVerticalScrollIndicator={false}
              renderItem={({ item }) => (
                <TouchableOpacity
                  style={[
                    styles.dropdownItem,
                    selectedCourse?.course_id === item.course_id && styles.dropdownItemSelected,
                  ]}
                  onPress={() => {
                    setSelectedCourse(item);
                    setShowCourseDropdown(false);
                    setErrors((prev) => ({ ...prev, course: null }));
                  }}
                >
                  <Text
                    style={[
                      styles.dropdownItemText,
                      selectedCourse?.course_id === item.course_id && styles.dropdownItemTextSelected,
                    ]}
                  >
                    {item.course_code} - {item.course_name}
                  </Text>
                  {selectedCourse?.course_id === item.course_id && (
                    <Ionicons name="checkmark-circle" size={20} color={COLORS.primary} />
                  )}
                </TouchableOpacity>
              )}
            />
          </View>
        </TouchableOpacity>
      </Modal>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safeArea: { flex: 1, backgroundColor: COLORS.surface },
  container: { flex: 1, backgroundColor: COLORS.surface },
  scrollContent: { flexGrow: 1, paddingHorizontal: 24, paddingBottom: 40 },
  backButton: { flexDirection: "row", alignItems: "center", marginBottom: 8 },
  backText: { fontSize: 16, color: COLORS.primary, marginLeft: 4 },
  header: { marginBottom: 24 },
  title: { fontSize: 28, fontWeight: "bold", color: COLORS.textPrimary },
  subtitle: { fontSize: 16, color: COLORS.textSecondary, marginTop: 4 },
  form: { flex: 1 },
  inputGroup: { marginBottom: 16 },
  label: { fontSize: 14, fontWeight: "600", color: COLORS.textSecondary, marginBottom: 6 },
  required: { color: COLORS.danger },
  roleContainer: { flexDirection: "row", gap: 10 },
  roleOption: {
    flex: 1,
    paddingVertical: 12,
    borderRadius: 10,
    borderWidth: 1.5,
    borderColor: COLORS.border,
    alignItems: "center",
    backgroundColor: COLORS.surfaceAlt,
  },
  roleOptionSelected: { backgroundColor: COLORS.primary, borderColor: COLORS.primary },
  roleText: { fontSize: 14, color: COLORS.textSecondary, fontWeight: "500" },
  roleTextSelected: { color: COLORS.surface },
  dropdownTrigger: {
    flexDirection: "row",
    justifyContent: "space-between",
    alignItems: "center",
    borderWidth: 1.5,
    borderColor: COLORS.border,
    borderRadius: 12,
    paddingHorizontal: 14,
    paddingVertical: 14,
    backgroundColor: COLORS.surfaceAlt,
  },
  dropdownTriggerText: { fontSize: 16, color: COLORS.textPrimary },
  dropdownPlaceholder: { color: COLORS.textMuted },
  inputError: { borderColor: COLORS.danger },
  errorText: { color: COLORS.danger, fontSize: 12, marginTop: 4 },
  createButton: { marginTop: 8 },
  modalOverlay: {
    flex: 1,
    backgroundColor: "rgba(0,0,0,0.5)",
    justifyContent: "center",
    alignItems: "center",
  },
  modalContent: {
    backgroundColor: COLORS.surface,
    borderRadius: 16,
    padding: 20,
    width: "90%",
    maxHeight: "60%",
  },
  modalHeader: {
    flexDirection: "row",
    justifyContent: "space-between",
    alignItems: "center",
    marginBottom: 16,
    paddingBottom: 12,
    borderBottomWidth: 1,
    borderBottomColor: COLORS.border,
  },
  modalTitle: { fontSize: 18, fontWeight: "bold", color: COLORS.textPrimary },
  dropdownItem: {
    flexDirection: "row",
    justifyContent: "space-between",
    alignItems: "center",
    paddingVertical: 14,
    paddingHorizontal: 12,
    borderBottomWidth: 1,
    borderBottomColor: COLORS.border,
  },
  dropdownItemSelected: { backgroundColor: COLORS.surfaceAlt },
  dropdownItemText: { fontSize: 16, color: COLORS.textSecondary },
  dropdownItemTextSelected: { color: COLORS.primary, fontWeight: "600" },
});
