import { useEffect, useState } from "react";
import { Ionicons } from "@expo/vector-icons";
import {
  Alert,
  KeyboardAvoidingView,
  Platform,
  ScrollView,
  StyleSheet,
  Text,
  TouchableOpacity,
  View,
} from "react-native";
import { useRouter } from "expo-router";
import { SafeAreaView } from "react-native-safe-area-context";
import SelectField from "../../components/adminResources/SelectField";
import CustomInput from "../../components/ui/CustomInput";
import PrimaryButton from "../../components/ui/PrimaryButton";
import { COLORS, RADIUS, SPACING } from "../../constants/colors";
import { useAuth } from "../../contexts/AuthContext";
import { fetchCourses, fetchDepartments } from "../../services/api/adminResources";
import {
  createAdminStudent,
  createAdminUser,
  fetchAllInstructors,
} from "../../services/api/adminUsers";

const ROLES = [
  { label: "Student", value: "student", icon: "school-outline" },
  { label: "Instructor", value: "instructor", icon: "person-outline" },
  { label: "Staff", value: "staff", icon: "briefcase-outline" },
  { label: "Dept Head", value: "department_head", icon: "ribbon-outline" },
  { label: "Admin", value: "admin", icon: "shield-checkmark-outline" },
];

export default function AdminCreateUserScreen() {
  const router = useRouter();
  const { token } = useAuth();

  const [role, setRole] = useState("student");
  const [fullName, setFullName] = useState("");
  const [email, setEmail] = useState("");
  const [studentNumber, setStudentNumber] = useState("");
  const [departmentId, setDepartmentId] = useState("");
  const [courseId, setCourseId] = useState("");
  const [specialization, setSpecialization] = useState("");
  const [qualification, setQualification] = useState("");
  const [jobTitle, setJobTitle] = useState("");
  const [instructorUserId, setInstructorUserId] = useState("");

  const [departments, setDepartments] = useState([]);
  const [courses, setCourses] = useState([]);
  const [instructors, setInstructors] = useState([]);
  const [instructorsLoading, setInstructorsLoading] = useState(true);
  const [instructorsError, setInstructorsError] = useState(null);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState(null);

  useEffect(() => {
    let isMounted = true;
    async function loadData() {
      if (!token) {
        setInstructorsError("Your session is unavailable. Please sign in again.");
        setInstructorsLoading(false);
        return;
      }
      try {
        const [deptRes, courseRes] = await Promise.all([
          fetchDepartments(token),
          fetchCourses(token),
        ]);
        if (isMounted) {
          if (deptRes.success && Array.isArray(deptRes.data)) {
            setDepartments(deptRes.data);
          }
          if (courseRes.success && Array.isArray(courseRes.data)) {
            setCourses(courseRes.data);
          }
        }
      } catch (loadError) {
        if (isMounted) {
          setError(loadError.message || "Could not load form data.");
        }
      }

      try {
        const instructorRes = await fetchAllInstructors(token);
        if (!instructorRes.success || !Array.isArray(instructorRes.data)) {
          throw new Error(instructorRes.message || "Could not load instructors.");
        }
        if (isMounted) {
          setInstructors(instructorRes.data);
        }
      } catch (loadError) {
        if (isMounted) setInstructorsError(loadError.message || "Could not load instructors.");
      } finally {
        if (isMounted) setInstructorsLoading(false);
      }
    }
    loadData();
    return () => {
      isMounted = false;
    };
  }, [token]);

  const departmentOptions = departments.map((d) => ({
    label: d.department_name,
    value: String(d.department_id),
  }));

  const filteredCourses = departmentId
    ? courses.filter(
        (c) =>
          !c.department_id ||
          String(c.department_id) === String(departmentId)
      )
    : courses;

  const courseOptions = filteredCourses.map((c) => ({
    label: `${c.course_name} (${c.course_code})`,
    value: String(c.course_id),
  }));
  const instructorOptions = instructors.map((instructor) => ({
    label: `${instructor.full_name} (${instructor.email})`,
    value: String(instructor.user_id),
  }));

  const handleSave = async () => {
    setError(null);

    if (role === "department_head") {
      if (!instructorUserId) {
        setError("Select an instructor to promote.");
        return;
      }

      setSaving(true);
      try {
        const res = await createAdminUser(token, {
          role: "department_head",
          instructor_user_id: Number(instructorUserId),
        });
        if (!res.success) {
          throw new Error(res.message || "Could not promote instructor.");
        }
        Alert.alert(
          "Success",
          "Instructor promoted to Department Head.",
          [{ text: "OK", onPress: () => router.back() }],
        );
      } catch (saveError) {
        setError(saveError.message || "Could not promote instructor.");
      } finally {
        setSaving(false);
      }
      return;
    }

    if (!fullName.trim()) {
      setError("Full Name is required.");
      return;
    }

    if (role === "student") {
      const cleanStudentNum = studentNumber.replace(/\D/g, "");
      if (!cleanStudentNum) {
        setError("Please enter a valid numeric student number.");
        return;
      }

      setSaving(true);
      try {
        const payload = {
          full_name: fullName.trim(),
          student_number: cleanStudentNum,
          email: `${cleanStudentNum}@student.school.ac.ug`,
          department_id: departmentId ? Number(departmentId) : null,
          course_id: courseId ? Number(courseId) : null,
        };

        const res = await createAdminStudent(token, payload);
        setSaving(false);
        if (res.success) {
          Alert.alert(
            "Success",
            `Pre-approved student created.\nEmail: ${payload.email}`,
            [{ text: "OK", onPress: () => router.back() }]
          );
        } else {
          setError(res.message || "Failed to create student.");
        }
      } catch {
        setSaving(false);
        setError("Network error. Please try again.");
      }
      return;
    }

    // Instructor, Staff, Dept Head, Admin
    if (!email.trim()) {
      setError("Email address is required.");
      return;
    }

    setSaving(true);
    try {
      const payload = {
        full_name: fullName.trim(),
        email: email.trim().toLowerCase(),
        role,
        department_id: departmentId ? Number(departmentId) : null,
        specialization: specialization.trim(),
        qualification: qualification.trim(),
        job_title: jobTitle.trim(),
      };

      const res = await createAdminUser(token, payload);
      setSaving(false);
      if (res.success) {
        Alert.alert(
          "Success",
          `User created successfully.\nDefault password: Welcome123!`,
          [{ text: "OK", onPress: () => router.back() }]
        );
      } else {
        setError(res.message || "Failed to create user.");
      }
    } catch {
      setSaving(false);
      setError("Network error. Please try again.");
    }
  };

  return (
    <SafeAreaView style={styles.safeArea}>
      <KeyboardAvoidingView
        style={styles.keyboardView}
        behavior={Platform.OS === "ios" ? "padding" : "height"}
      >
        <View style={styles.header}>
          <TouchableOpacity
            style={styles.backButton}
            onPress={() => router.back()}
            accessibilityRole="button"
          >
            <Ionicons name="arrow-back" size={22} color={COLORS.primary} />
          </TouchableOpacity>
          <Text style={styles.title}>Create User</Text>
          <View style={styles.placeholder} />
        </View>

        <ScrollView
          contentContainerStyle={styles.scrollContent}
          keyboardShouldPersistTaps="handled"
        >
          {/* Step 1: Role Selection */}
          <Text style={styles.sectionTitle}>Select Role</Text>
          <View style={styles.roleGrid}>
            {ROLES.map((r) => {
              const selected = role === r.value;
              return (
                <TouchableOpacity
                  key={r.value}
                  style={[styles.roleCard, selected && styles.roleCardActive]}
                  onPress={() => {
                    setRole(r.value);
                    setError(null);
                    setInstructorUserId("");
                  }}
                  accessibilityRole="button"
                >
                  <Ionicons
                    name={r.icon}
                    size={22}
                    color={selected ? COLORS.surface : COLORS.textPrimary}
                  />
                  <Text
                    style={[
                      styles.roleCardText,
                      selected && styles.roleCardTextActive,
                    ]}
                  >
                    {r.label}
                  </Text>
                </TouchableOpacity>
              );
            })}
          </View>

          {/* Step 2: Dynamic Form */}
          <Text style={styles.sectionTitle}>User Details</Text>

          {role === "department_head" ? (
            <>
              {instructorsLoading ? (
                <Text style={styles.hintText}>Loading instructors…</Text>
              ) : instructorOptions.length > 0 ? (
                <SelectField
                  label="Instructor *"
                  value={instructorUserId}
                  options={instructorOptions}
                  onChange={setInstructorUserId}
                />
              ) : (
                <Text style={styles.errorText}>
                  {instructorsError || "No instructors are available to promote."}
                </Text>
              )}
              <Text style={styles.hintText}>
                The selected instructor’s existing account details will be retained.
              </Text>
            </>
          ) : (
            <CustomInput
              label="Full Name *"
              value={fullName}
              onChangeText={setFullName}
              placeholder="e.g. John Doe"
              autoCapitalize="words"
            />
          )}

          {role === "student" ? (
            <>
              <CustomInput
                label="Student Number (Digits Only) *"
                value={studentNumber}
                onChangeText={(val) => setStudentNumber(val.replace(/\D/g, ""))}
                placeholder="e.g. 202600123"
                keyboardType="numeric"
              />
              <Text style={styles.hintText}>
                Email will be:{" "}
                <Text style={styles.hintHighlight}>
                  {studentNumber ? `${studentNumber}@student.school.ac.ug` : "[number]@student.school.ac.ug"}
                </Text>
              </Text>

              <SelectField
                label="Department"
                value={departmentId}
                options={departmentOptions}
                onChange={setDepartmentId}
              />

              <SelectField
                label="Course"
                value={courseId}
                options={courseOptions}
                onChange={setCourseId}
              />
            </>
          ) : role !== "department_head" ? (
            <>
              <CustomInput
                label="Email *"
                value={email}
                onChangeText={setEmail}
                placeholder={
                  role === "instructor" || role === "staff"
                    ? "firstname.lastname@school.ac.ug"
                    : "admin@school.ac.ug"
                }
                keyboardType="email-address"
                autoCapitalize="none"
              />

              <SelectField
                label="Department"
                value={departmentId}
                options={departmentOptions}
                onChange={setDepartmentId}
              />

              {role === "instructor" && (
                <>
                  <CustomInput
                    label="Specialization"
                    value={specialization}
                    onChangeText={setSpecialization}
                    placeholder="e.g. Network Security & Cloud Computing"
                  />
                  <CustomInput
                    label="Qualification"
                    value={qualification}
                    onChangeText={setQualification}
                    placeholder="e.g. MSc Computer Science"
                  />
                </>
              )}

              {role === "staff" && (
                <CustomInput
                  label="Job Title"
                  value={jobTitle}
                  onChangeText={setJobTitle}
                  placeholder="e.g. Lab Technician"
                />
              )}

              <Text style={styles.hintText}>
                Initial Password will default to:{" "}
                <Text style={styles.hintHighlight}>Welcome123!</Text>
              </Text>
            </>
          ) : null}

          {error && <Text style={styles.errorText}>{error}</Text>}

          <View style={styles.buttonWrapper}>
            <PrimaryButton
              title="Create User"
              onPress={handleSave}
              loading={saving}
            />
          </View>
        </ScrollView>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safeArea: {
    flex: 1,
    backgroundColor: COLORS.background,
  },
  keyboardView: {
    flex: 1,
  },
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
  backButton: {
    padding: SPACING.xs,
  },
  title: {
    fontSize: 18,
    fontWeight: "700",
    color: COLORS.textPrimary,
  },
  placeholder: {
    width: 24,
  },
  scrollContent: {
    padding: SPACING.md,
    paddingBottom: SPACING.xl,
  },
  sectionTitle: {
    fontSize: 14,
    fontWeight: "700",
    color: COLORS.textSecondary,
    textTransform: "uppercase",
    letterSpacing: 0.5,
    marginTop: SPACING.sm,
    marginBottom: SPACING.sm,
  },
  roleGrid: {
    flexDirection: "row",
    flexWrap: "wrap",
    gap: SPACING.xs,
    marginBottom: SPACING.md,
  },
  roleCard: {
    flexDirection: "row",
    alignItems: "center",
    paddingVertical: 10,
    paddingHorizontal: 12,
    borderRadius: RADIUS.md,
    borderWidth: 1,
    borderColor: COLORS.border,
    backgroundColor: COLORS.surface,
    gap: 6,
  },
  roleCardActive: {
    backgroundColor: COLORS.primary,
    borderColor: COLORS.primary,
  },
  roleCardText: {
    fontSize: 13,
    fontWeight: "600",
    color: COLORS.textPrimary,
  },
  roleCardTextActive: {
    color: COLORS.surface,
  },
  hintText: {
    fontSize: 12,
    color: COLORS.textSecondary,
    marginTop: -SPACING.xs,
    marginBottom: SPACING.sm,
  },
  hintHighlight: {
    fontWeight: "600",
    color: COLORS.primary,
  },
  errorText: {
    color: COLORS.danger,
    fontSize: 14,
    marginBottom: SPACING.sm,
  },
  buttonWrapper: {
    marginTop: SPACING.md,
  },
});
