import { useEffect, useState } from "react";
import axios from "axios";
import {
  Table,
  TableHead,
  TableRow,
  TableHeaderCell,
  TableBody,
  TableCell,
} from "@/components/ui/table";
import { Button } from "@/components/ui/button";

export default function Dashboard() {
  const [records, setRecords] = useState([]);
  const [startDate, setStartDate] = useState("2025-01-01");
  const [endDate, setEndDate] = useState(new Date().toISOString().slice(0, 10));
  const [lastUploadedDate, setLastUploadedDate] = useState("");

  useEffect(() => {
    fetchData();
    fetchLastUpload();
  }, []);

  const fetchData = async () => {
    try {
      const response = await axios.get(
        `/api/fetch_timerecords.php?start_date=${startDate}&end_date=${endDate}`
      );
      setRecords(response.data);
    } catch (error) {
      console.error("Error fetching records", error);
    }
  };

  const fetchLastUpload = async () => {
    try {
      const response = await axios.get("/api/last_upload.php");
      setLastUploadedDate(response.data.last_uploaded_date);
    } catch (error) {
      console.error("Error fetching last uploaded date", error);
    }
  };

  const handleSearch = (e) => {
    e.preventDefault();
    fetchData();
  };

  return (
    <div className="p-6 max-w-screen-lg mx-auto">
      <div className="text-center mb-4">
        <img
          src="https://cohere.ph/img/cohere-logo.jpg"
          alt="Cohere Logo"
          className="mx-auto w-48"
        />
      </div>

      <div className="text-center mb-4">
        <strong>Last Uploaded Date:</strong>{" "}
        {lastUploadedDate || "Loading..."}
      </div>

      <form
        onSubmit={handleSearch}
        className="flex flex-wrap gap-4 items-end mb-6"
      >
        <div>
          <label className="block font-semibold mb-1">Start Date</label>
          <input
            type="date"
            value={startDate}
            onChange={(e) => setStartDate(e.target.value)}
            className="border p-2 rounded w-full"
          />
        </div>
        <div>
          <label className="block font-semibold mb-1">End Date</label>
          <input
            type="date"
            value={endDate}
            onChange={(e) => setEndDate(e.target.value)}
            className="border p-2 rounded w-full"
          />
        </div>
        <Button type="submit" className="bg-red-600 text-white">
          Search
        </Button>
      </form>

      <div className="mb-4 text-sm text-gray-600">
        📌 <strong>Cut-off period:</strong> Every 23rd–7th and 8th–22nd of the
        month
      </div>

      <h3 className="text-xl font-bold mb-3">Raw Time Records</h3>

      <Table>
        <TableHead>
          <TableRow>
            <TableHeaderCell>Employee ID</TableHeaderCell>
            <TableHeaderCell>First Name</TableHeaderCell>
            <TableHeaderCell>Last Name</TableHeaderCell>
            <TableHeaderCell>Day</TableHeaderCell>
            <TableHeaderCell>Time</TableHeaderCell>
            <TableHeaderCell>Type</TableHeaderCell>
          </TableRow>
        </TableHead>
        <TableBody>
          {records.length === 0 ? (
            <TableRow>
              <TableCell colSpan={6} className="text-center">
                No records found.
              </TableCell>
            </TableRow>
          ) : (
            records.map((record, index) => (
              <TableRow key={index}>
                <TableCell>{record.EmployeeID}</TableCell>
                <TableCell>{record.FirstName}</TableCell>
                <TableCell>{record.LastName}</TableCell>
                <TableCell>{record.Day}</TableCell>
                <TableCell>{record.Time}</TableCell>
                <TableCell>{record.Type}</TableCell>
              </TableRow>
            ))
          )}
        </TableBody>
      </Table>
    </div>
  );
}
